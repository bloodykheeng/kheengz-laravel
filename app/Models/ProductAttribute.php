<?php

namespace App\Models;

use App\Contracts\HasSnapshotSchema;
use App\Enums\AttributeInputType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What describes products for filters (D32): Colour, Capacity, Gauge. Defined once by the Admin and
 * given to categories. docs/catalogue.md.
 *
 * @property string $id
 * @property string $name
 * @property AttributeInputType $input_type
 * @property string|null $unit
 * @property string|null $hint
 */
class ProductAttribute extends Model implements HasSnapshotSchema
{
    use HasUlids;

    protected $fillable = [
        'name',
        'input_type',
        'unit',
        'hint',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return ['input_type' => AttributeInputType::class];
    }

    /** @return array<string, array<string, mixed>> */
    public static function snapshotSchema(): array
    {
        return [
            'name' => ['label' => 'Name', 'type' => 'string'],
            'input_type' => ['label' => 'Filled in as', 'type' => 'string'],
            'unit' => ['label' => 'Unit', 'type' => 'string'],
            'hint' => ['label' => 'Hint', 'type' => 'string'],
            'options' => [
                'label' => 'Choices',
                'kind' => 'list',
                'max' => 100,
                'current' => fn (self $m) => $m->options()->get(['id', 'value'])->all(),
                'item' => [
                    'id' => ['identity' => true, 'hidden' => true],
                    'value' => ['label' => 'Choice', 'type' => 'string'],
                ],
            ],
        ];
    }

    /** Whether any product or variant option uses it, which keeps it from being deleted. */
    public function isInUse(): bool
    {
        return ProductAttributeValue::query()->where('product_attribute_id', $this->id)->exists()
            || ProductOption::query()->where('product_attribute_id', $this->id)->exists();
    }

    /** @return HasMany<ProductAttributeOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(ProductAttributeOption::class)->orderBy('position')->orderBy('value');
    }

    /** @return BelongsToMany<Category, $this> */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_product_attribute')->withPivot(['position', 'is_required'])->withTimestamps();
    }
}
