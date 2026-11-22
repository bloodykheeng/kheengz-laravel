<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One choice of a List attribute (Colour: Red).
 *
 * @property string $id
 * @property string $product_attribute_id
 * @property string $value
 * @property int $position
 */
class ProductAttributeOption extends Model
{
    use HasUlids;

    protected $fillable = ['product_attribute_id', 'value', 'position'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    /** Whether a product or a variant's option value points at it. */
    public function isInUse(): bool
    {
        return ProductAttributeValue::query()->where('product_attribute_option_id', $this->id)->exists()
            || ProductOptionValue::query()->where('product_attribute_option_id', $this->id)->exists();
    }

    /** @return BelongsTo<ProductAttribute, $this> */
    public function productAttribute(): BelongsTo
    {
        return $this->belongsTo(ProductAttribute::class);
    }
}
