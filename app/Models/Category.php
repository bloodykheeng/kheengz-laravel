<?php

namespace App\Models;

use App\Contracts\HasSnapshotSchema;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One node of the marketplace's category tree, kept by the Admin (D32). docs/catalogue.md.
 *
 * @property string $id
 * @property string|null $parent_id
 * @property string $name
 * @property string $slug
 * @property string|null $icon
 * @property int $position
 * @property bool $is_active
 * @property-read Category|null $parent
 */
class Category extends Model implements HasSnapshotSchema
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory, HasUlids;

    /** Household > Kitchen > Basins > Round basins at most: deeper trees only make buyers dig. */
    public const MAX_DEPTH = 4;

    protected $fillable = [
        'parent_id',
        'name',
        'slug',
        'icon',
        'position',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function snapshotSchema(): array
    {
        return [
            'name' => ['label' => 'Name', 'type' => 'string'],
            'parent_id' => ['label' => 'Inside', 'type' => 'relation', 'resolve' => fn ($id) => self::query()->whereKey($id)->value('name')],
            'slug' => ['label' => 'Address name', 'type' => 'string'],
            'icon' => ['label' => 'Icon', 'type' => 'string'],
            'position' => ['label' => 'Position', 'type' => 'number'],
            'is_active' => ['label' => 'On', 'type' => 'boolean'],
            'attributes' => [
                'label' => 'Attributes',
                'kind' => 'list',
                'current' => fn (self $m) => $m->productAttributes()->get()
                    ->map(fn (ProductAttribute $a) => ['id' => $a->id, 'name' => $a->name, 'is_required' => (bool) $a->getRelationValue('pivot')?->getAttribute('is_required')])->all(),
                'item' => [
                    'id' => ['identity' => true, 'hidden' => true],
                    'name' => ['label' => 'Attribute', 'type' => 'string'],
                    'is_required' => ['label' => 'Required', 'type' => 'boolean'],
                ],
            ],
        ];
    }

    /**
     * Its ancestors' ids then its own, root first. The tree is small (hundreds of rows) and changes
     * rarely, so walking up is a handful of indexed reads.
     *
     * @return list<string>
     */
    public function lineageIds(): array
    {
        $ids = [$this->id];
        $parentId = $this->parent_id;

        while ($parentId !== null && count($ids) <= self::MAX_DEPTH) {
            array_unshift($ids, $parentId);
            $parentId = self::query()->whereKey($parentId)->value('parent_id');
        }

        return $ids;
    }

    /** How deep it sits: 1 for a top category. */
    public function depth(): int
    {
        return count($this->lineageIds());
    }

    /** "Household › Kitchen › Basins", for pickers and notices. */
    public function path(): string
    {
        $names = self::query()->whereIn('id', $this->lineageIds())->pluck('name', 'id');

        return collect($this->lineageIds())->map(fn (string $id) => $names[$id] ?? '')->implode(' › ');
    }

    /** Whether `$other` is this category or sits somewhere under it. */
    public function isSelfOrAncestorOf(Category $other): bool
    {
        return in_array($this->id, $other->lineageIds(), true);
    }

    /**
     * Every attribute its products are described by: its parents' first, then its own, each in the
     * order the Admin set. Each carries `is_required` from the category that gave it.
     *
     * @return Collection<int, ProductAttribute>
     */
    public function effectiveAttributes(): Collection
    {
        $lineage = $this->lineageIds();

        /** @var Collection<int, ProductAttribute> $attributes */
        $attributes = ProductAttribute::query()
            ->join('category_product_attribute as cpa', 'cpa.product_attribute_id', '=', 'product_attributes.id')
            ->whereIn('cpa.category_id', $lineage)
            ->select('product_attributes.*', 'cpa.category_id as from_category_id', 'cpa.is_required', 'cpa.position as category_position')
            ->with('options')
            ->get();

        return $attributes
            ->sortBy(fn (ProductAttribute $a) => [array_search($a->getAttribute('from_category_id'), $lineage, true), $a->getAttribute('category_position')])
            ->unique('id')
            ->values();
    }

    /**
     * @param  Builder<Category>  $query
     * @return Builder<Category>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** @return BelongsTo<Category, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<Category, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position')->orderBy('name');
    }

    /** @return BelongsToMany<ProductAttribute, $this> */
    public function productAttributes(): BelongsToMany
    {
        return $this->belongsToMany(ProductAttribute::class, 'category_product_attribute')
            ->withPivot(['position', 'is_required'])
            ->withTimestamps()
            ->orderByPivot('position');
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
