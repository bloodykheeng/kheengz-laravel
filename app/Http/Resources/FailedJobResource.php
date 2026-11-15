<?php

namespace App\Http\Resources;

use App\Models\FailedJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FailedJob */
class FailedJobResource extends JsonResource
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
            'uuid' => $this->uuid,
            'connection' => $this->connection,
            'queue' => $this->queue,
            'payload' => $this->payload,
            'exception' => $this->exception,
            'failed_at' => $this->failed_at,
        ];
    }
}
