<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PayoutDestinationStatus;
use App\Enums\ShopStatus;
use App\Enums\ShopType;
use App\Http\Controllers\Controller;
use App\Http\Resources\ShopResource;
use App\Models\Shop;
use App\Models\Vendor;
use App\Rules\PhoneNumber;
use App\Rules\ShopSlug;
use App\Services\ShopReviews;
use App\Support\PerPage;
use App\Support\Phone;
use App\Traits\LoggableTrait;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Shops (D31): reviewers see every shop; a vendor's people the shops they run. A shop is made as a
 * Draft with its first location, sent for review by the Owner, and opens once approved. Its name,
 * address name and type are fixed once it has been approved, so an approved shop cannot turn into
 * another one. docs/vendors-and-shops.md.
 */
#[Group('Shops', 'Shops, their review and their locations.', weight: 4)]
class ShopController extends Controller
{
    use LoggableTrait;

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        // Reviewers see every shop; anyone else the shops they run.
        $visible = Shop::query()->when(! $user->can('view vendors'), fn (Builder $q) => $q->runBy($user));

        $shops = QueryBuilder::for($visible)
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('shop_type'),
                AllowedFilter::exact('vendor_id'),
                AllowedFilter::callback('search', fn (Builder $q, $value) => $q->where(fn (Builder $w) => $w
                    ->where('name', 'like', "%{$value}%")
                    ->orWhere('slug', 'like', "%{$value}%"))),
            )
            ->allowedSorts('name', 'status', 'created_at', 'approved_at')
            ->defaultSort('-created_at')
            ->allowedIncludes('createdBy', 'updatedBy', 'approvedBy')
            ->with('vendor')
            ->withCount('locations')
            ->paginate(PerPage::from($request))
            ->appends($request->query());

        return ShopResource::collection($shops);
    }

    public function show(Request $request, Shop $shop): ShopResource
    {
        $this->authorize('view', $shop);

        return new ShopResource($shop->load([
            'vendor',
            'payoutDestination',
            'locations' => fn ($q) => $q->orderByDesc('is_default')->orderBy('name'),
            'reviews' => fn ($q) => $q->with('createdBy')->latest('created_at')->latest('id'),
            'approvedBy', 'createdBy', 'updatedBy',
        ]));
    }

    /** The Owner opens a new shop as a Draft, with its first location (D5). */
    public function store(Request $request, Vendor $vendor): JsonResponse
    {
        $this->authorize('own', $vendor);
        $this->normalise($request);

        $validated = $request->validate([
            ...$this->detailRules(null),
            'location_address' => ['required', 'string', 'max:255'],
            'location_area' => ['required', 'string', 'max:120'],
            'location_opening_hours' => ['nullable', 'string', 'max:255'],
        ], ['location_address.required' => 'Say where the shop is, so riders and buyers can find it.']);

        $by = $request->user()->id;
        $shop = DB::transaction(function () use ($vendor, $validated, $by) {
            $shop = $vendor->shops()->create([
                ...collect($validated)->only(['name', 'slug', 'shop_type', 'description', 'phone', 'email'])->all(),
                'status' => ShopStatus::Draft,
                'created_by' => $by,
                'updated_by' => $by,
            ]);
            $shop->locations()->create([
                'name' => 'Main shop',
                'address' => $validated['location_address'],
                'area' => $validated['location_area'],
                'phone' => $validated['phone'],
                'opening_hours' => $validated['location_opening_hours'] ?? null,
                'is_default' => true,
                'created_by' => $by,
                'updated_by' => $by,
            ]);

            return $shop;
        });

        $this->logActivity('shop_created', "Shop {$shop->name} created by {$vendor->name}.", ['snapshot' => $this->buildModelSnapshot($shop)], $shop);

        return response()->json(['message' => "{$shop->name} is saved as a draft. Send it for review when it is ready.", 'data' => new ShopResource($shop->load('vendor'))], 201);
    }

    public function update(Request $request, Shop $shop): JsonResponse
    {
        $this->authorize('update', $shop);
        $this->normalise($request);

        $validated = $request->validate([
            ...$this->detailRules($shop),
            // Where the money goes: one of the vendor's destinations that is not refused, the Owner's call.
            'payout_destination_id' => ['sometimes', 'nullable', 'string', Rule::exists('payout_destinations', 'id')
                ->where('vendor_id', $shop->vendor_id)->whereNot('status', PayoutDestinationStatus::Rejected->value)],
        ]);
        abort_if(array_key_exists('payout_destination_id', $validated) && ! $shop->vendor->isOwnedBy($request->user()), 403, 'Only an Owner chooses where a shop is paid.');

        if ($shop->approved_at !== null) {
            $shop->fill($validated);
            abort_if($shop->isDirty(['name', 'slug', 'shop_type']), 422, 'The name, address name and type were approved with the shop, so they stay. Contact support to change them.');
        }

        $before = $this->captureOriginal($shop);
        $shop->update([...$validated, 'updated_by' => $request->user()->id]);
        $this->logActivity('shop_updated', "Shop {$shop->name} updated.", ['changes' => $this->buildChanges($shop, $before)], $shop);

        return response()->json(['message' => 'Saved.', 'data' => new ShopResource($shop->fresh('vendor'))]);
    }

    public function submit(Request $request, Shop $shop, ShopReviews $reviews): JsonResponse
    {
        $this->authorize('own', $shop);
        abort_if($shop->locations()->doesntExist(), 422, 'Add where the shop is before sending it for review.');

        $reviews->submit($shop, $request->user());
        $this->logActivity('shop_submitted', "Shop {$shop->name} sent for review.", [], $shop);

        return response()->json(['message' => "{$shop->name} is sent for review. We will tell you as soon as it is decided.", 'data' => new ShopResource($shop->fresh('vendor'))]);
    }

    public function close(Request $request, Shop $shop, ShopReviews $reviews): JsonResponse
    {
        $this->authorize('own', $shop);
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null;

        $reviews->close($shop, $reason, $request->user());
        $this->logActivity('shop_closed', "Shop {$shop->name} closed by its vendor.", ['reason' => $reason], $shop);

        return response()->json(['message' => "{$shop->name} is closed.", 'data' => new ShopResource($shop->fresh('vendor'))]);
    }

    /** Whether an address name is free, for the form to say so as it is typed. */
    public function slugAvailability(Request $request): JsonResponse
    {
        $slug = strtolower(trim((string) $request->query('slug', '')));
        $validator = validator(['slug' => $slug], ['slug' => [new ShopSlug($request->query('ignore'))]]);

        return response()->json([
            'slug' => $slug,
            'available' => $validator->passes(),
            'message' => $validator->errors()->first('slug') ?: "{$slug}.".config('app.shops_domain').' is free.',
        ]);
    }

    /** @return array<string, list<mixed>> */
    private function detailRules(?Shop $shop): array
    {
        $required = $shop === null ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'min:2', 'max:120'],
            'slug' => [$required, 'string', new ShopSlug($shop?->id)],
            'shop_type' => [$required, Rule::enum(ShopType::class)],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'phone' => [$required, 'string', new PhoneNumber],
            'email' => ['sometimes', 'nullable', 'email', 'max:190'],
        ];
    }

    private function normalise(Request $request): void
    {
        $request->merge(array_filter([
            'phone' => $request->has('phone') ? (Phone::international($request->input('phone')) ?? $request->input('phone')) : null,
            'slug' => $request->has('slug') ? strtolower(trim((string) $request->input('slug'))) : null,
        ], fn ($v) => $v !== null));
    }
}
