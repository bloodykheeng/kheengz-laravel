<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What the buyer picks and pays for: a Red 20 L basin. Its name is its option values in the options'
 * order, worked out, never stored. docs/catalogue.md.
 *
 * @property string $id
 * @property string $product_id
 * @property string|null $sku
 * @property string|null $barcode
 * @property string $unit_id
 * @property int $price
 * @property int|null $compare_at_price
 * @property int|null $weight_grams
 * @property int $position
 * @property bool $is_active
 * @property-read Product $product
 * @property-read Unit $unit
 * @property-read Collection<int, ProductOptionValue> $optionValues
 * @property-read Collection<int, PriceBreak> $priceBreaks
 */
class ProductVariant extends Model
{
    use HasUlids;

    /** A wholesale ladder longer than this is a price list, not price breaks. */
    public const MAX_PRICE_BREAKS = 5;

    protected $fillable = [
        'product_id',
        'sku',
        'barcode',
        'unit_id',
        'price',
        'compare_at_price',
        'weight_grams',
        'position',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'compare_at_price' => 'integer',
            'weight_grams' => 'integer',
            'position' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** "Red / 20 L"; empty for the one variant of a product with no options. */
    public function label(): string
    {
        return $this->optionValues
            ->sortBy(fn (ProductOptionValue $v) => $v->option->position)
            ->pluck('value')
            ->implode(' / ');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return BelongsToMany<ProductOptionValue, $this> */
    public function optionValues(): BelongsToMany
    {
        return $this->belongsToMany(ProductOptionValue::class, 'product_variant_option_value');
    }

    /** @return HasMany<PriceBreak, $this> */
    public function priceBreaks(): HasMany
    {
        return $this->hasMany(PriceBreak::class)->orderBy('min_quantity');
    }
}
