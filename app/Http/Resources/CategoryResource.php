<?php

namespace App\Http\Resources;

use App\Models\Category;
use App\Models\ProductAttribute;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A category. The tree comes as a flat list (parent_id, depth, path): the Next app builds the tree,
 * and a picker searches the paths.
 *
 * @mixin Category
 */
class CategoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'icon' => $this->icon,
            'position' => $this->position,
            'is_active' => $this->is_active,
            // Worked out for a whole tree at once by withLineage(); one at a time otherwise.
            'depth' => $this->getAttribute('tree_depth') ?? $this->depth(),
            'path' => $this->getAttribute('tree_path') ?? $this->path(),
            'products_count' => $this->whenCounted('products'),
            'children_count' => $this->whenCounted('children'),
            // Its own attributes (the Admin's screen); `effective_attributes` adds its parents' (the product form).
            'attributes' => $this->whenLoaded('productAttributes', fn () => $this->productAttributes->map(fn (ProductAttribute $a) => [
                ...(new ProductAttributeResource($a))->toArray($request),
                'is_required' => (bool) $a->getRelationValue('pivot')?->getAttribute('is_required'),
            ])),
            'effective_attributes' => $this->when($this->relationLoaded('effectiveAttributes'), fn () => collect($this->getRelation('effectiveAttributes'))->map(fn (ProductAttribute $a) => [
                ...(new ProductAttributeResource($a))->toArray($request),
                'is_required' => (bool) $a->getAttribute('is_required'),
                'from_category_id' => $a->getAttribute('from_category_id'),
            ])),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * Gives each category its depth and path from one read of the table, so a tree of hundreds costs
     * one query instead of a walk per row. Kept on the models only for this response (never saved).
     *
     * @param  iterable<Category>  $all  the whole tree
     */
    public static function withLineage(iterable $all): void
    {
        $byId = collect($all)->keyBy('id');
        foreach ($byId as $category) {
            $names = [$category->name];
            $parent = $category->parent_id;
            $guard = 0;
            while ($parent !== null && isset($byId[$parent]) && $guard++ < Category::MAX_DEPTH) {
                array_unshift($names, $byId[$parent]->name);
                $parent = $byId[$parent]->parent_id;
            }
            $category->setAttribute('tree_depth', count($names));
            $category->setAttribute('tree_path', implode(' › ', $names));
        }
    }
}
