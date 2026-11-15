<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
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
            'name' => $this->name,
            'email' => $this->email,
            'username' => $this->username,
            'phone' => $this->phone,
            'allow_notifications' => $this->allow_notifications,
            'status' => $this->status,
            'role' => $this->role,
            'lastlogin' => $this->lastlogin,
            'photo_url' => $this->photo_url,
            'photo_source' => $this->photo_source,
            'photo_source_url' => $this->photo_source_url,
            'timezone' => $this->timezone,
            'email_verified_at' => $this->email_verified_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            // Serialised only when the request asked for it with
            // `include=`, so a list that does not need a relation pays
            // nothing for it and the key is omitted rather than null.
            'created_by' => new UserReferenceResource($this->whenLoaded('createdBy')),
            'updated_by' => new UserReferenceResource($this->whenLoaded('updatedBy')),
        ];
    }
}
