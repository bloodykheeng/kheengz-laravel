<?php

namespace App\Http\Resources;

use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Unit */
class UnitResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'plural' => $this->plural,
            'allows_decimals' => $this->allows_decimals,
            'position' => $this->position,
            'is_active' => $this->is_active,
            // Set when the list was asked for a shop type: this unit is one that type sells by.
            'suggested' => $this->when($this->getAttribute('suggested') !== null, fn () => (bool) $this->getAttribute('suggested')),
        ];
    }
}
