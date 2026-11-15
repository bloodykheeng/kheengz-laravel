<?php

namespace App\Http\Resources;

use App\Models\VendorDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A vendor's paper. No file URL: the file is private and fetched through
 * GET /vendor-documents/{id}/file with the caller's token.
 *
 * @mixin VendorDocument
 */
class VendorDocumentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'checked_at' => $this->checked_at,
            'created_at' => $this->created_at,
        ];
    }
}
