<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\VendorResource;
use App\Models\Vendor;
use App\Services\VendorApprovals;
use App\Traits\LoggableTrait;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The Admin's decisions on a vendor (D35): approve, reject, suspend, reinstate. */
#[Group('Vendors', 'Vendor applications, vendors, their documents and members.', weight: 3)]
class VendorReviewController extends Controller
{
    use LoggableTrait;

    public function __construct(private VendorApprovals $approvals) {}

    public function approve(Request $request, Vendor $vendor): JsonResponse
    {
        $this->authorize('review', $vendor);
        $this->approvals->approve($vendor, $request->user());
        $this->logActivity('vendor_approved', "Vendor {$vendor->name} approved.", [], $vendor);

        return $this->answer($vendor, "{$vendor->name} is approved. They have been told.");
    }

    public function reject(Request $request, Vendor $vendor): JsonResponse
    {
        $this->authorize('review', $vendor);
        $reason = $this->reason($request);
        $this->approvals->reject($vendor, $reason, $request->user());
        $this->logActivity('vendor_rejected', "Vendor {$vendor->name} rejected: {$reason}", ['reason' => $reason], $vendor);

        return $this->answer($vendor, "{$vendor->name} was told why their application was not approved.");
    }

    public function suspend(Request $request, Vendor $vendor): JsonResponse
    {
        $this->authorize('review', $vendor);
        $reason = $this->reason($request);
        $this->approvals->suspend($vendor, $reason, $request->user());
        $this->logActivity('vendor_suspended', "Vendor {$vendor->name} suspended: {$reason}", ['reason' => $reason], $vendor);

        return $this->answer($vendor, "{$vendor->name} is suspended. Their shops are closed until you reinstate them.");
    }

    public function reinstate(Request $request, Vendor $vendor): JsonResponse
    {
        $this->authorize('review', $vendor);
        $this->approvals->reinstate($vendor, $request->user());
        $this->logActivity('vendor_reinstated', "Vendor {$vendor->name} reinstated.", [], $vendor);

        return $this->answer($vendor, "{$vendor->name} is active again.");
    }

    private function reason(Request $request): string
    {
        return $request->validate(
            ['reason' => ['required', 'string', 'min:5', 'max:500']],
            ['reason.required' => 'Say why, in a sentence the vendor will read.'],
        )['reason'];
    }

    private function answer(Vendor $vendor, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'data' => new VendorResource($vendor->fresh())]);
    }
}
