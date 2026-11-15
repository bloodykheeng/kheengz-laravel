<?php

namespace App\Http\Resources;

use App\Models\Vendor;
use App\Services\VendorApprovals;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A vendor. The national ID number is shown in full only to people who review vendors and to the
 * vendor's own members; anyone else would never reach this resource (VendorPolicy).
 *
 * @mixin Vendor
 */
class VendorResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'kind' => $this->kind,
            'national_id_number' => $this->national_id_number,
            'registration_number' => $this->registration_number,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'status' => $this->status,
            'status_reason' => $this->status_reason,
            'approved_at' => $this->approved_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            // The caller's own place in this vendor (Owner, Staff), or null for a reviewer.
            'my_role' => $this->whenLoaded('members', fn () => $this->members->firstWhere('id', $request->user()?->id)?->membership?->role),
            'shops_count' => $this->whenCounted('shops'),
            'missing_documents' => $this->whenLoaded('documents', fn () => array_map(fn ($t) => $t->value, VendorApprovals::missingDocuments($this->resource))),

            'documents' => VendorDocumentResource::collection($this->whenLoaded('documents')),
            'shops' => ShopResource::collection($this->whenLoaded('shops')),
            'members' => VendorMemberResource::collection($this->whenLoaded('members')),
            'approved_by' => new UserReferenceResource($this->whenLoaded('approvedBy')),
            'created_by' => new UserReferenceResource($this->whenLoaded('createdBy')),
            'updated_by' => new UserReferenceResource($this->whenLoaded('updatedBy')),
        ];
    }
}
