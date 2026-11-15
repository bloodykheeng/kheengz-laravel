<?php

namespace App\Http\Resources;

use App\Models\Activity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Activity */
class ActivityLogResource extends JsonResource
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
            'log_name' => $this->log_name,
            'description' => $this->description,
            'event' => $this->event,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'causer_type' => $this->causer_type,
            'causer_id' => $this->causer_id,
            'properties' => $this->properties,
            'attribute_changes' => $this->attribute_changes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            // Serialised only when the request asked for it with
            // `include=`, so a list that does not need a relation pays
            // nothing for it and the key is omitted rather than null.
            'causer' => new UserResource($this->whenLoaded('causer')),
        ];
    }
}
