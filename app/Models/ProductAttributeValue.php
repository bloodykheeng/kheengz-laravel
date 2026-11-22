<?php

namespace App\Models;

use App\Enums\AttributeInputType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A product's value for one attribute of its category: a choice, a number or text.
 *
 * @property string $id
 * @property string $product_id
 * @property string $product_attribute_id
 * @property string|null $product_attribute_option_id
 * @property string|null $value_number
 * @property string|null $value_text
 * @property-read ProductAttribute $productAttribute
 * @property-read ProductAttributeOption|null $option
 */
class ProductAttributeValue extends Model
{
    use HasUlids;

    protected $fillable = [
        'product_id',
        'product_attribute_id',
        'product_attribute_option_id',
        'value_number',
        'value_text',
    ];

    protected function casts(): array
    {
        return ['value_number' => 'decimal:3'];
    }

    /** As a person reads it: "Red", "20 L", "Kenpoly". */
    public function display(): string
    {
        return match ($this->productAttribute->input_type) {
            AttributeInputType::List => (string) $this->option?->value,
            AttributeInputType::Number => trim(self::trimNumber((string) $this->value_number).' '.($this->productAttribute->unit ?? '')),
            AttributeInputType::Text => (string) $this->value_text,
        };
    }

    /** "20.000" reads "20", "1.500" reads "1.5". */
    public static function trimNumber(string $number): string
    {
        return str_contains($number, '.') ? rtrim(rtrim($number, '0'), '.') : $number;
    }

    /** @return BelongsTo<ProductAttribute, $this> */
    public function productAttribute(): BelongsTo
    {
        return $this->belongsTo(ProductAttribute::class);
    }

    /** @return BelongsTo<ProductAttributeOption, $this> */
    public function option(): BelongsTo
    {
        return $this->belongsTo(ProductAttributeOption::class, 'product_attribute_option_id');
    }
}
