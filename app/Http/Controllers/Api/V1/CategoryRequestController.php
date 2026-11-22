<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CategoryRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryRequestResource;
use App\Models\Category;
use App\Models\CategoryRequest;
use App\Models\Shop;
use App\Models\User;
use App\Notifications\CategoryRequestDecided;
use App\Notifications\CategoryRequested;
use App\Services\CategoryTree;
use App\Services\Notifier;
use App\Support\PerPage;
use App\Traits\LoggableTrait;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Requests for a missing category (D32). A shop asks from its product form; the Admin adds it (a new
 * category, or one that already fits) or declines with a reason; whoever asked is told either way.
 * docs/catalogue.md.
 */
#[Group('Catalogue', 'Categories, attributes, units and category requests.', weight: 5)]
class CategoryRequestController extends Controller
{
    use LoggableTrait;

    /** The Admin sees every request; a vendor's people those of the shops they run. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $visible = CategoryRequest::query()->when(! $user->can('manage catalogue'), fn (Builder $q) => $q->whereIn('shop_id', Shop::query()->runBy($user)->select('id')));

        $requests = QueryBuilder::for($visible)
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('shop_id'),
                AllowedFilter::callback('search', fn (Builder $q, $value) => $q->where('name', 'like', "%{$value}%")),
            )
            ->allowedSorts('created_at', 'name', 'status')
            ->defaultSort('-created_at')
            ->with(['shop', 'parent', 'category', 'createdBy', 'decidedBy'])
            ->paginate(PerPage::from($request))
            ->appends($request->query());

        return CategoryRequestResource::collection($requests);
    }

    public function store(Request $request, Shop $shop, Notifier $notifier): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'parent_id' => ['nullable', 'string', Rule::exists('categories', 'id')->where('is_active', true)],
            'note' => ['nullable', 'string', 'max:500'],
        ], ['name.required' => 'Say what the category should be called.']);

        $existing = Category::query()->where('is_active', true)->whereRaw('lower(name) = ?', [mb_strtolower(trim($validated['name']))])->first();
        if ($existing) {
            throw ValidationException::withMessages(['name' => ["There is already {$existing->path()}. Choose it on the product."]]);
        }
        $pending = $shop->categoryRequests()->where('status', CategoryRequestStatus::Pending)->whereRaw('lower(name) = ?', [mb_strtolower(trim($validated['name']))])->exists();
        if ($pending) {
            throw ValidationException::withMessages(['name' => ['You have already asked for this one. We will tell you when it is decided.']]);
        }

        $by = $request->user()->id;
        $categoryRequest = $shop->categoryRequests()->create([
            ...$validated,
            'name' => trim($validated['name']),
            'status' => CategoryRequestStatus::Pending,
            'created_by' => $by,
            'updated_by' => $by,
        ]);

        $this->logActivity('category_requested', "{$shop->name} asked for the category {$categoryRequest->name}.", ['snapshot' => $this->buildModelSnapshot($categoryRequest)], $categoryRequest);
        $notifier->admins(new CategoryRequested($categoryRequest), $request->user());

        return response()->json(['message' => "We will tell you when \"{$categoryRequest->name}\" is decided, usually the same day. Until then, put the product in the nearest category.", 'data' => new CategoryRequestResource($categoryRequest)], 201);
    }

    /**
     * Answer it with a category: a new one made here (name, place, icon) or one that already fits
     * (`category_id`).
     */
    public function add(Request $request, CategoryRequest $categoryRequest, CategoryTree $tree): JsonResponse
    {
        $this->assertPending($categoryRequest);
        $validated = $request->validate([
            'category_id' => ['required_without:name', 'nullable', 'string', Rule::exists('categories', 'id')],
            'name' => ['required_without:category_id', 'nullable', 'string', 'min:2', 'max:80'],
            'parent_id' => ['nullable', 'string', Rule::exists('categories', 'id')],
            'icon' => ['nullable', 'string', 'max:40', 'regex:/^pi-[a-z0-9-]+$/'],
            'answer' => ['nullable', 'string', 'max:500'],
        ]);

        $by = $request->user();
        DB::transaction(function () use ($categoryRequest, $validated, $by, $tree) {
            $categoryId = $validated['category_id'] ?? null;
            if ($categoryId === null) {
                $made = $tree->create(['name' => $validated['name'], 'parent_id' => $validated['parent_id'] ?? null, 'icon' => $validated['icon'] ?? null], $by);
                $this->logActivity('category_created', "Category {$made->path()} created for a shop's request.", ['snapshot' => $this->buildModelSnapshot($made)], $made);
                $categoryId = $made->id;
            }

            $categoryRequest->update([
                'status' => CategoryRequestStatus::Added,
                'category_id' => $categoryId,
                'answer' => $validated['answer'] ?? null,
                'decided_at' => now(),
                'decided_by' => $by->id,
                'updated_by' => $by->id,
            ]);
        });

        return $this->decided($categoryRequest, 'category_request_added', 'Added. We told the shop.');
    }

    public function decline(Request $request, CategoryRequest $categoryRequest): JsonResponse
    {
        $this->assertPending($categoryRequest);
        $validated = $request->validate(['answer' => ['required', 'string', 'min:5', 'max:500']], ['answer.required' => 'Tell the shop why, and where their products can go instead.']);

        $by = $request->user()->id;
        $categoryRequest->update([
            'status' => CategoryRequestStatus::Declined,
            'answer' => $validated['answer'],
            'decided_at' => now(),
            'decided_by' => $by,
            'updated_by' => $by,
        ]);

        return $this->decided($categoryRequest, 'category_request_declined', 'Declined. We told the shop why.');
    }

    private function decided(CategoryRequest $categoryRequest, string $event, string $message): JsonResponse
    {
        $categoryRequest->load(['shop', 'parent', 'category', 'createdBy', 'decidedBy']);
        $this->logActivity($event, "Category request \"{$categoryRequest->name}\" from {$categoryRequest->shop->name}: {$categoryRequest->status->value}.", ['snapshot' => $this->buildModelSnapshot($categoryRequest)], $categoryRequest);

        $asker = $categoryRequest->createdBy;
        if ($asker instanceof User) {
            $asker->notify(new CategoryRequestDecided($categoryRequest));
        }

        return response()->json(['message' => $message, 'data' => new CategoryRequestResource($categoryRequest)]);
    }

    /** A request already answered (on another screen, a moment ago) is not answered twice. */
    private function assertPending(CategoryRequest $categoryRequest): void
    {
        if ($categoryRequest->status !== CategoryRequestStatus::Pending) {
            throw ValidationException::withMessages(['status' => ["This request was already {$categoryRequest->status->value}."]]);
        }
    }
}
