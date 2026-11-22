<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Wholesale (D17): from `min_quantity` of the variant's unit, each costs `unit_price`.
 *
 * @property string $id
 * @property string $product_variant_id
 * @property string $min_quantity
 * @property int $unit_price
 */
class PriceBreak extends Model
{
    use HasUlids;

    protected $fillable = ['product_variant_id', 'min_quantity', 'unit_price'];

    protected function casts(): array
    {
        return [
            'min_quantity' => 'decimal:3',
            'unit_price' => 'integer',
        ];
    }

    /** "12", "2.5": the quantity as a person writes it. */
    public function quantityLabel(): string
    {
        return ProductAttributeValue::trimNumber((string) $this->min_quantity);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
