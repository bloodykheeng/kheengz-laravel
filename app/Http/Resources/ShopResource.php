<?php

namespace App\Http\Resources;

use App\Models\Shop;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Shop */
class ShopResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vendor_id' => $this->vendor_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'address' => $this->address(),
            'shop_type' => $this->shop_type,
            'description' => $this->description,
            'phone' => $this->phone,
            'email' => $this->email,
            'status' => $this->status,
            'status_reason' => $this->status_reason,
            'approved_at' => $this->approved_at,
            'commission_bps' => $this->commission_bps,
            'debt_limit_amount' => $this->debt_limit_amount,
            'debt_limit_days' => $this->debt_limit_days,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            'vendor' => $this->whenLoaded('vendor', fn () => [
                'id' => $this->vendor->id,
                'name' => $this->vendor->name,
                'status' => $this->vendor->status,
            ]),
            'locations_count' => $this->whenCounted('locations'),
            'locations' => LocationResource::collection($this->whenLoaded('locations')),
            'reviews' => ShopReviewResource::collection($this->whenLoaded('reviews')),
            'approved_by' => new UserReferenceResource($this->whenLoaded('approvedBy')),
            'created_by' => new UserReferenceResource($this->whenLoaded('createdBy')),
            'updated_by' => new UserReferenceResource($this->whenLoaded('updatedBy')),
        ];
    }
}
