<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Services\CategoryTree;
use App\Traits\LoggableTrait;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The marketplace's category tree (D32): read by vendors to place products, kept by the Admin
 * (`manage catalogue`). The whole tree comes in one list, not paged: it is the menu, a few hundred
 * rows at most, and a picker needs all of it. docs/catalogue.md.
 */
#[Group('Catalogue', 'Categories, attributes, units and category requests.', weight: 5)]
class CategoryController extends Controller
{
    use LoggableTrait;

    public function __construct(private CategoryTree $tree) {}

    /** The tree, flat, in menu order. `?active=1` for pickers: only categories that are on, under parents that are on. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $all = Category::query()->withCount(['products', 'children'])->orderBy('position')->orderBy('name')->get();
        CategoryResource::withLineage($all);

        if ($request->boolean('active')) {
            $byId = $all->keyBy('id');
            $all = $all->filter(function (Category $c) use ($byId) {
                for ($node = $c; $node !== null; $node = $node->parent_id ? $byId[$node->parent_id] ?? null : null) {
                    if (! $node->is_active) {
                        return false;
                    }
                }

                return true;
            })->values();
        }

        return CategoryResource::collection($this->inTreeOrder($all));
    }

    /** One category with its own attributes and, for the product form, its parents' too. */
    public function show(Category $category): CategoryResource
    {
        $category->load('productAttributes.options')->loadCount(['products', 'children']);
        $category->setRelation('effectiveAttributes', $category->effectiveAttributes());

        return new CategoryResource($category);
    }

    public function store(Request $request): JsonResponse
    {
        $category = $this->tree->create($this->validated($request, null), $request->user());

        $this->logActivity('category_created', "Category {$category->path()} created.", ['snapshot' => $this->buildModelSnapshot($category)], $category);

        return response()->json(['message' => "{$category->name} added.", 'data' => new CategoryResource($category)], 201);
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        $validated = $this->validated($request, $category);

        $before = $this->captureOriginal($category);
        $category->fill($validated);
        if ($category->isDirty(['name', 'parent_id'])) {
            $category->slug = $this->tree->uniqueSlug($category->name, $category->parent_id, $category->id);
        }
        $category->updated_by = $request->user()->id;
        $category->save();

        $this->logActivity('category_updated', "Category {$category->path()} updated.", ['changes' => $this->buildChanges($category, $before)], $category);

        return response()->json(['message' => 'Saved.', 'data' => new CategoryResource($category)]);
    }

    /** Only an empty one, never used: anything else is turned off instead, so no product loses its place. */
    public function destroy(Category $category): JsonResponse
    {
        abort_if($category->children()->exists(), 422, 'Move or delete the categories inside it first.');
        abort_if($category->products()->exists(), 422, 'Products are in this category. Turn it off instead, so they keep their place until moved.');
        abort_if(DB::table('category_requests')->where('category_id', $category->id)->orWhere('parent_id', $category->id)->exists(), 422, 'A vendor\'s request points at this category. Turn it off instead.');

        $snapshot = $this->buildModelSnapshot($category);
        $path = $category->path();
        DB::transaction(function () use ($category) {
            $category->productAttributes()->detach();
            $category->delete();
        });
        $this->logActivity('category_deleted', "Category {$path} deleted.", ['snapshot' => $snapshot], $category);

        return response()->json(['message' => "{$category->name} deleted."]);
    }

    /** Its own attributes, in the order given; children inherit them. */
    public function syncAttributes(Request $request, Category $category): JsonResponse
    {
        $validated = $request->validate([
            'attributes' => ['present', 'array', 'max:30'],
            'attributes.*.id' => ['required', 'string', 'distinct', Rule::exists('product_attributes', 'id')],
            'attributes.*.is_required' => ['sometimes', 'boolean'],
        ]);

        $inherited = collect($category->effectiveAttributes())->where('from_category_id', '!=', $category->id)->pluck('name', 'id');
        foreach ($validated['attributes'] as $i => $row) {
            if ($inherited->has($row['id'])) {
                throw ValidationException::withMessages(["attributes.{$i}.id" => ["{$inherited[$row['id']]} already comes from a category above this one."]]);
            }
        }

        $before = $this->captureOriginal($category);
        $category->productAttributes()->sync(collect($validated['attributes'])->values()->mapWithKeys(fn ($row, $i) => [
            $row['id'] => ['position' => $i, 'is_required' => (bool) ($row['is_required'] ?? false)],
        ])->all());
        $this->logActivity('category_attributes_updated', "Attributes of {$category->path()} updated.", ['changes' => $this->buildChanges($category, $before)], $category);

        return response()->json(['message' => 'Saved.', 'data' => $this->show($category)]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Category $category): array
    {
        $required = $category === null ? 'required' : 'sometimes';

        $validated = $request->validate([
            'name' => [$required, 'string', 'min:2', 'max:80'],
            'parent_id' => ['sometimes', 'nullable', 'string', Rule::exists('categories', 'id')],
            'icon' => ['sometimes', 'nullable', 'string', 'max:40', 'regex:/^pi-[a-z0-9-]+$/'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'is_active' => ['sometimes', 'boolean'],
        ], ['icon.regex' => 'Choose an icon from the list.']);

        // A new category is checked by CategoryTree::create; an edit here, against where it ends up.
        if ($category !== null) {
            $parentId = array_key_exists('parent_id', $validated) ? $validated['parent_id'] : $category->parent_id;
            $this->tree->assertNameFree($validated['name'] ?? $category->name, $parentId, $category->id);
            if (array_key_exists('parent_id', $validated)) {
                $this->tree->assertPlacement($category, $parentId);
            }
        }

        return $validated;
    }

    /**
     * Each category straight after its parent, siblings by position: the order a tree reads in.
     *
     * @param  Collection<int, Category>  $all
     * @return Collection<int, Category>
     */
    private function inTreeOrder(Collection $all): Collection
    {
        // Walking from the top also leaves out anything under a parent that was filtered out.
        $children = $all->groupBy(fn (Category $c) => $c->parent_id ?? '');
        $out = collect();
        $walk = function (string $parent) use (&$walk, $children, $out) {
            foreach ($children[$parent] ?? [] as $category) {
                $out->push($category);
                $walk($category->id);
            }
        };
        $walk('');

        return $out;
    }
}
