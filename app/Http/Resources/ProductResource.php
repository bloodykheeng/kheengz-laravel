<?php

namespace App\Http\Resources;

use App\Models\PriceBreak;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\ProductVariant;
use App\Models\ShopSection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A product. In a list: its cover, price range and counts. Opened: everything the product form
 * edits, in the shape it sends back.
 *
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'shop_id' => $this->shop_id,
            'category_id' => $this->category_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'status' => $this->status,
            'collect_only' => $this->collect_only,
            'published_at' => $this->published_at,
            'blocked_reason' => $this->blocked_reason,
            'blocked_at' => $this->blocked_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'path' => $this->category->path(),
                'is_active' => $this->category->is_active,
            ]),
            'cover' => $this->whenLoaded('photos', fn () => $this->photos->first() ? new ProductPhotoResource($this->photos->first()) : null),
            'min_price' => $this->whenAggregated('variants', 'price', 'min'),
            'max_price' => $this->whenAggregated('variants', 'price', 'max'),
            'variants_count' => $this->whenCounted('variants'),
            'photos_count' => $this->whenCounted('photos'),

            'section_ids' => $this->whenLoaded('sections', fn () => $this->sections->pluck('id')->values()),
            'sections' => $this->whenLoaded('sections', fn () => $this->sections->map(fn (ShopSection $s) => ['id' => $s->id, 'name' => $s->name])->values()),
            'attributes' => $this->whenLoaded('attributeValues', fn () => $this->attributeValues->map(fn (ProductAttributeValue $v) => [
                'attribute_id' => $v->product_attribute_id,
                'option_id' => $v->product_attribute_option_id,
                'number' => $v->value_number === null ? null : ProductAttributeValue::trimNumber((string) $v->value_number),
                'text' => $v->value_text,
                'display' => $v->relationLoaded('productAttribute') ? $v->display() : null,
            ])->values()),
            'options' => $this->whenLoaded('options', fn () => $this->options->map(fn (ProductOption $o) => [
                'id' => $o->id,
                'name' => $o->name,
                'attribute_id' => $o->product_attribute_id,
                'values' => $o->values->pluck('value')->values(),
            ])->values()),
            'variants' => $this->whenLoaded('variants', fn () => $this->variants->map(fn (ProductVariant $v) => [
                'id' => $v->id,
                'label' => $v->label(),
                // In the options' order, the way the form sends them.
                'option_values' => $v->optionValues->sortBy(fn (ProductOptionValue $ov) => $ov->option->position)->pluck('value')->values(),
                'sku' => $v->sku,
                'barcode' => $v->barcode,
                'unit_id' => $v->unit_id,
                'unit' => ['id' => $v->unit->id, 'name' => $v->unit->name, 'plural' => $v->unit->plural, 'allows_decimals' => $v->unit->allows_decimals],
                'price' => $v->price,
                'compare_at_price' => $v->compare_at_price,
                'weight_grams' => $v->weight_grams,
                'is_active' => $v->is_active,
                'price_breaks' => $v->priceBreaks->map(fn (PriceBreak $b) => ['min_quantity' => (float) $b->min_quantity, 'unit_price' => $b->unit_price])->values(),
            ])->values()),
            'photos' => ProductPhotoResource::collection($this->whenLoaded('photos')),
            'blocked_by' => new UserReferenceResource($this->whenLoaded('blockedBy')),
            'created_by' => new UserReferenceResource($this->whenLoaded('createdBy')),
            'updated_by' => new UserReferenceResource($this->whenLoaded('updatedBy')),
        ];
    }
}
