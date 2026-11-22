<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One value of a product option (Size: 20 L). When its option feeds an attribute, it may count as one
 * of that attribute's choices for filters.
 *
 * @property string $id
 * @property string $product_option_id
 * @property string $value
 * @property string|null $product_attribute_option_id
 * @property int $position
 * @property-read ProductOption $option
 */
class ProductOptionValue extends Model
{
    use HasUlids;

    protected $fillable = ['product_option_id', 'value', 'product_attribute_option_id', 'position'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    /** @return BelongsTo<ProductOption, $this> */
    public function option(): BelongsTo
    {
        return $this->belongsTo(ProductOption::class, 'product_option_id');
    }

    /** @return BelongsTo<ProductAttributeOption, $this> */
    public function attributeOption(): BelongsTo
    {
        return $this->belongsTo(ProductAttributeOption::class, 'product_attribute_option_id');
    }
}
