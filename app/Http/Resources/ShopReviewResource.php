<?php

namespace App\Http\Resources;

use App\Models\ShopReview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ShopReview */
class ShopReviewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'decision' => $this->decision,
            'reason' => $this->reason,
            'created_at' => $this->created_at,
            'created_by' => new UserReferenceResource($this->whenLoaded('createdBy')),
        ];
    }
}
