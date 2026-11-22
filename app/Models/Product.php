<?php

namespace App\Models;

use App\Contracts\HasSnapshotSchema;
use App\Enums\ProductStatus;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A product of one shop, in one marketplace category, sold as one or more variants (D32).
 * docs/catalogue.md.
 *
 * @property string $id
 * @property string $shop_id
 * @property string $category_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property ProductStatus $status
 * @property bool $collect_only
 * @property Carbon|null $published_at
 * @property string|null $blocked_reason
 * @property Carbon|null $blocked_at
 * @property-read Shop $shop
 * @property-read Category $category
 */
class Product extends Model implements HasSnapshotSchema
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, HasUlids;

    /** Size, Colour, Scent: three is where a buyer stops being able to choose (Shopify's limit too). */
    public const MAX_OPTIONS = 3;

    public const MAX_VARIANTS = 100;

    public const MAX_PHOTOS = 10;

    protected $fillable = [
        'shop_id',
        'category_id',
        'name',
        'slug',
        'description',
        'status',
        'collect_only',
        'published_at',
        'blocked_reason',
        'blocked_at',
        'blocked_by',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProductStatus::class,
            'collect_only' => 'boolean',
            'published_at' => 'datetime',
            'blocked_at' => 'datetime',
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function snapshotSchema(): array
    {
        return [
            'name' => ['label' => 'Name', 'type' => 'string'],
            'category_id' => ['label' => 'Category', 'type' => 'relation', 'resolve' => fn ($id) => Category::query()->find($id)?->path()],
            'slug' => ['label' => 'Address name', 'type' => 'string'],
            'description' => ['label' => 'Description', 'type' => 'text'],
            'status' => ['label' => 'Status', 'type' => 'string'],
            'collect_only' => ['label' => 'Collected only', 'type' => 'boolean'],
            'shop_id' => ['hidden' => true],
            'published_at' => ['hidden' => true],
            'blocked_reason' => ['label' => 'Taken down because', 'type' => 'text'],
            'blocked_at' => ['hidden' => true],
            'blocked_by' => ['hidden' => true],
            'sections' => [
                'label' => 'Shop sections',
                'kind' => 'list',
                'current' => fn (self $m) => $m->sections()->orderBy('name')->get(['shop_sections.id', 'shop_sections.name'])
                    ->map(fn (ShopSection $s) => ['id' => $s->id, 'name' => $s->name])->all(),
                'item' => [
                    'id' => ['identity' => true, 'hidden' => true],
                    'name' => ['label' => 'Section', 'type' => 'string'],
                ],
            ],
            'details' => [
                'label' => 'Details',
                'kind' => 'list',
                'current' => fn (self $m) => $m->attributeValues()->with(['productAttribute', 'option'])->get()
                    ->map(fn (ProductAttributeValue $v) => ['id' => $v->product_attribute_id, 'attribute' => $v->productAttribute->name, 'value' => $v->display()])->all(),
                'item' => [
                    'id' => ['identity' => true, 'hidden' => true],
                    'attribute' => ['label' => 'Attribute', 'type' => 'string'],
                    'value' => ['label' => 'Value', 'type' => 'string'],
                ],
            ],
            'variants' => [
                'label' => 'Variants',
                'kind' => 'list',
                'max' => self::MAX_VARIANTS,
                'current' => fn (self $m) => $m->variants()->with(['optionValues.option', 'unit', 'priceBreaks'])->get()
                    ->map(fn (ProductVariant $v) => [
                        'id' => $v->id,
                        'name' => $v->label(),
                        'sku' => $v->sku,
                        'unit' => $v->unit->name,
                        'price' => $v->price,
                        'compare_at_price' => $v->compare_at_price,
                        'price_breaks' => $v->priceBreaks->map(fn (PriceBreak $b) => "{$b->quantityLabel()}+ at {$b->unit_price}")->implode(', '),
                        'is_active' => $v->is_active,
                    ])->all(),
                'item' => [
                    'id' => ['identity' => true, 'hidden' => true],
                    'name' => ['label' => 'Variant', 'type' => 'string'],
                    'sku' => ['label' => 'SKU', 'type' => 'string'],
                    'unit' => ['label' => 'Unit', 'type' => 'string'],
                    'price' => ['label' => 'Price (UGX)', 'type' => 'number'],
                    'compare_at_price' => ['label' => 'Was (UGX)', 'type' => 'number'],
                    'price_breaks' => ['label' => 'Price breaks', 'type' => 'string'],
                    'is_active' => ['label' => 'Offered', 'type' => 'boolean'],
                ],
            ],
        ];
    }

    /** Taken down by Kheengz (D35): the vendor cannot put it back on sale until the Admin allows it. */
    public function isBlocked(): bool
    {
        return $this->blocked_at !== null;
    }

    /**
     * A Draft, never on sale and with nothing on the shelf: nothing can point at it yet, so it may be
     * deleted. Others are hidden.
     */
    public function isDeletable(): bool
    {
        return $this->status === ProductStatus::Draft && $this->published_at === null
            && StockLevel::query()->whereIn('product_variant_id', $this->variants()->select('id'))->where('on_hand', '!=', 0)->doesntExist();
    }

    /** @return BelongsTo<Shop, $this> */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsToMany<ShopSection, $this> */
    public function sections(): BelongsToMany
    {
        return $this->belongsToMany(ShopSection::class)->withPivot('position')->withTimestamps();
    }

    /** @return HasMany<ProductAttributeValue, $this> */
    public function attributeValues(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class);
    }

    /** @return HasMany<ProductOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(ProductOption::class)->orderBy('position');
    }

    /** @return HasMany<ProductVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('position');
    }

    /** @return HasMany<ProductVariant, $this> */
    public function trackedVariants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->where('track_stock', true);
    }

    /** @return HasMany<ProductPhoto, $this> */
    public function photos(): HasMany
    {
        return $this->hasMany(ProductPhoto::class)->orderBy('position');
    }

    /** @return BelongsTo<User, $this> */
    public function blockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_by');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
