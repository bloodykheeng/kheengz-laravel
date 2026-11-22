<?php

namespace App\Http\Resources;

use App\Models\ProductAttribute;
use App\Models\ProductAttributeOption;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProductAttribute */
class ProductAttributeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'input_type' => $this->input_type,
            'unit' => $this->unit,
            'hint' => $this->hint,
            'options' => $this->whenLoaded('options', fn () => $this->options->map(fn (ProductAttributeOption $o) => [
                'id' => $o->id,
                'value' => $o->value,
                'position' => $o->position,
            ])->values()),
            'categories_count' => $this->whenCounted('categories'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
