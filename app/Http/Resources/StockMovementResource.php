<?php

namespace App\Http\Resources;

use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StockMovement */
class StockMovementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'variant_id' => $this->product_variant_id,
            'variant_label' => $this->whenLoaded('variant', fn () => $this->variant->label()),
            'unit' => $this->whenLoaded('variant', fn () => $this->variant->relationLoaded('unit') ? $this->variant->unit->plural : null),
            'location_id' => $this->location_id,
            'location' => $this->whenLoaded('location', fn () => $this->location->name),
            'reason' => $this->reason,
            'on_hand_change' => (float) $this->on_hand_change,
            'reserved_change' => (float) $this->reserved_change,
            'on_hand_after' => (float) $this->on_hand_after,
            'reserved_after' => (float) $this->reserved_after,
            'note' => $this->note,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'created_at' => $this->created_at,
            'created_by' => new UserReferenceResource($this->whenLoaded('createdBy')),
        ];
    }
}
