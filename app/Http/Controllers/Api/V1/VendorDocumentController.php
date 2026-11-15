<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\VendorDocumentType;
use App\Enums\VendorStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\VendorDocumentResource;
use App\Models\Vendor;
use App\Models\VendorDocument;
use App\Notifications\VendorApplied;
use App\Services\Notifier;
use App\Services\VendorApprovals;
use App\Traits\LoggableTrait;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A vendor's papers for approval (D35). Files go to the private disk under the vendor's folder and
 * come back only through file(), to the vendor's Owner and to people who review vendors.
 * docs/vendors-and-shops.md.
 */
#[Group('Vendors', 'Vendor applications, vendors, their documents and members.', weight: 3)]
class VendorDocumentController extends Controller
{
    use LoggableTrait;

    /** 10 MB: a phone photo of an ID card, or a scanned certificate. */
    public const MAX_KB = 10240;

    /**
     * Add a paper. Uploading another of the same type replaces it. When this completes the papers of a
     * Pending vendor, the Admins are told the application is ready to review.
     */
    public function store(Request $request, Vendor $vendor, Notifier $notifier): JsonResponse
    {
        $this->authorize('own', $vendor);

        $validated = $request->validate([
            'type' => ['required', Rule::enum(VendorDocumentType::class)],
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:'.self::MAX_KB],
        ], [
            'file.mimes' => 'Upload a photo (JPG, PNG, WebP) or a PDF.',
            'file.max' => 'The file is over 10 MB. Take the photo again at a smaller size, or scan to PDF.',
        ]);

        $wasMissing = VendorApprovals::missingDocuments($vendor) !== [];
        $file = $request->file('file');
        $type = VendorDocumentType::from($validated['type']);

        $previous = $vendor->documents()->where('type', $type)->get();
        $document = $vendor->documents()->create([
            'type' => $type,
            'file_path' => $file->store("vendor-documents/{$vendor->id}", VendorDocument::DISK),
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime_type' => (string) $file->getMimeType(),
            'size_bytes' => (int) $file->getSize(),
            'created_by' => $request->user()->id,
        ]);
        $previous->each->delete();

        $this->logActivity('vendor_document_added', "{$vendor->name} added {$type->value}.", ['snapshot' => $this->buildModelSnapshot($document)], $vendor);

        if ($wasMissing && $vendor->status === VendorStatus::Pending && VendorApprovals::missingDocuments($vendor) === []) {
            $notifier->admins(new VendorApplied($vendor), $request->user());
        }

        return response()->json(['message' => "{$type->value} uploaded.", 'data' => new VendorDocumentResource($document)], 201);
    }

    /**
     * Remove a paper. Not once the vendor is approved: the approval rested on it. They upload a new
     * one instead, which replaces it.
     */
    public function destroy(Request $request, VendorDocument $document): JsonResponse
    {
        $vendor = $document->vendor;
        $this->authorize('own', $vendor);
        abort_if($vendor->status === VendorStatus::Approved, 422, 'Your approval rested on this paper, so it stays. Upload a new one to replace it.');

        $snapshot = $this->buildModelSnapshot($document);
        $document->delete();
        $this->logActivity('vendor_document_deleted', "{$vendor->name} removed {$document->type->value}.", ['snapshot' => $snapshot], $vendor);

        return response()->json(['message' => 'Removed.']);
    }

    /** The file itself, to the vendor's Owner or a reviewer; never cached by the browser or a proxy. */
    public function file(Request $request, VendorDocument $document): StreamedResponse
    {
        $vendor = $document->vendor;
        abort_unless($request->user()->can('view vendors') || $vendor->isOwnedBy($request->user()), 404);

        return Storage::disk(VendorDocument::DISK)->response($document->file_path, $document->original_name, [
            'Content-Type' => $document->mime_type,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** A reviewer marks that they looked at it. */
    public function check(Request $request, VendorDocument $document): JsonResponse
    {
        $this->authorize('review', $document->vendor);
        $document->update(['checked_at' => now(), 'checked_by' => $request->user()->id]);
        $this->logActivity('vendor_document_checked', "{$document->type->value} of {$document->vendor->name} checked.", [], $document->vendor);

        return response()->json(['message' => 'Marked as checked.', 'data' => new VendorDocumentResource($document)]);
    }
}
