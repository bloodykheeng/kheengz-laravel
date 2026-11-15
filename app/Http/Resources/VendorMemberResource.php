<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Someone who works for a vendor: who they are, their place (Owner, Staff) and, for Staff, the
 * shops they were given.
 *
 * @mixin User
 */
class VendorMemberResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'status' => $this->status,
            'role' => $this->membership?->role,
            'shop_ids' => $this->whenLoaded('staffShops', fn () => $this->staffShops->pluck('id')->values()),
        ];
    }
}
