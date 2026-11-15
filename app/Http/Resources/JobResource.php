<?php

namespace App\Http\Resources;

use App\Models\Job;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Job */
class JobResource extends JsonResource
{
    /**
     * The record as a flat object.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'queue' => $this->queue,
            'payload' => $this->payload,
            'attempts' => $this->attempts,
            'reserved_at' => $this->reserved_at,
            'available_at' => $this->available_at,
            'created_at' => $this->created_at,
        ];
    }
}
