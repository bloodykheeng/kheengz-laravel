<?php

namespace App\Http\Resources;

use App\Models\CategoryRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CategoryRequest */
class CategoryRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'shop_id' => $this->shop_id,
            'name' => $this->name,
            'parent_id' => $this->parent_id,
            'note' => $this->note,
            'status' => $this->status,
            'category_id' => $this->category_id,
            'answer' => $this->answer,
            'decided_at' => $this->decided_at,
            'created_at' => $this->created_at,
            'shop' => $this->whenLoaded('shop', fn () => ['id' => $this->shop->id, 'name' => $this->shop->name, 'shop_type' => $this->shop->shop_type]),
            'parent' => $this->whenLoaded('parent', fn () => $this->parent ? ['id' => $this->parent->id, 'name' => $this->parent->name, 'path' => $this->parent->path()] : null),
            'category' => $this->whenLoaded('category', fn () => $this->category ? ['id' => $this->category->id, 'name' => $this->category->name, 'path' => $this->category->path()] : null),
            'created_by' => new UserReferenceResource($this->whenLoaded('createdBy')),
            'decided_by' => new UserReferenceResource($this->whenLoaded('decidedBy')),
        ];
    }
}
