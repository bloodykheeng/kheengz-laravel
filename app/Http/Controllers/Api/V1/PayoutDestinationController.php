<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MobileNetwork;
use App\Enums\PayoutMethod;
use App\Http\Controllers\Controller;
use App\Http\Resources\PayoutDestinationResource;
use App\Models\PayoutDestination;
use App\Models\Vendor;
use App\Payments\GatewayUnavailable;
use App\Payments\MarzPay\MarzPayClient;
use App\Services\PayoutDestinations;
use App\Traits\LoggableTrait;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Where a vendor's money goes (D12, D31): the Owner adds Mobile Money numbers and bank accounts,
 * reviewers decide on the ones that wait. Never edited; a change is a new destination.
 * docs/vendors-and-shops.md "Payout destinations".
 */
#[Group('Vendors', 'Vendor applications, vendors, their documents and members.', weight: 3)]
class PayoutDestinationController extends Controller
{
    use LoggableTrait;

    public function __construct(private PayoutDestinations $destinations) {}

    public function index(Request $request, Vendor $vendor): AnonymousResourceCollection
    {
        return PayoutDestinationResource::collection($vendor->payoutDestinations()->latest()->latest('id')->get());
    }

    /** The banks MarzPay can check and pay into, for the bank picker. */
    public function banks(MarzPayClient $marzpay): JsonResponse
    {
        try {
            return response()->json(['data' => $marzpay->banks()]);
        } catch (GatewayUnavailable $e) {
            report($e);

            return response()->json(['message' => 'The list of banks could not be loaded just now. Try again in a minute, or add a Mobile Money number.'], 503);
        }
    }

    public function store(Request $request, Vendor $vendor): JsonResponse
    {
        $method = $request->input('method');
        $validated = $request->validate([
            'method' => ['required', Rule::enum(PayoutMethod::class)],
            'network' => [Rule::requiredIf($method === PayoutMethod::MobileMoney->value), 'nullable', Rule::enum(MobileNetwork::class)],
            'bank_name' => [Rule::requiredIf($method === PayoutMethod::Bank->value), 'nullable', 'string', 'max:120'],
            'account_number' => ['required', 'string', 'max:40'],
            'account_name' => ['required', 'string', 'min:2', 'max:160'],
        ], [
            'bank_name.required' => 'Choose the bank.',
            'network.required' => 'Choose MTN or Airtel.',
            'account_name.required' => 'Enter the name the account is registered in, as the bank or network has it.',
        ]);

        $destination = $this->destinations->add($vendor, $validated, $request->user());
        $this->logActivity('payout_destination_added', "Payout destination {$destination->label()} added to {$vendor->name} ({$destination->status->value}).", ['snapshot' => $this->buildModelSnapshot($destination)], $vendor);

        return response()->json([
            'message' => $destination->status_reason === null
                ? "{$destination->label()} is checked and ready."
                : "{$destination->label()} is saved. {$destination->status_reason} We will tell you once it is decided.",
            'data' => new PayoutDestinationResource($destination),
        ], 201);
    }

    /** Remove one no shop is paid to. */
    public function destroy(Request $request, PayoutDestination $destination): JsonResponse
    {
        $vendor = $destination->vendor;
        abort_unless($vendor->isOwnedBy($request->user()), 403);

        $snapshot = $this->buildModelSnapshot($destination);
        $destination->delete();
        $this->logActivity('payout_destination_deleted', "Payout destination {$destination->label()} removed from {$vendor->name}.", ['snapshot' => $snapshot], $vendor);

        return response()->json(['message' => 'Removed.']);
    }

    public function approve(Request $request, PayoutDestination $destination): JsonResponse
    {
        $this->destinations->approve($destination, $request->user());
        $this->logActivity('payout_destination_approved', "Payout destination {$destination->label()} of {$destination->vendor->name} approved.", [], $destination->vendor);

        return response()->json(['message' => 'Approved. The vendor has been told.', 'data' => new PayoutDestinationResource($destination->fresh())]);
    }

    public function reject(Request $request, PayoutDestination $destination): JsonResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']], ['reason.required' => 'Say why, in a sentence the vendor will read.'])['reason'];
        $this->destinations->reject($destination, $reason, $request->user());
        $this->logActivity('payout_destination_rejected', "Payout destination {$destination->label()} of {$destination->vendor->name} rejected: {$reason}", ['reason' => $reason], $destination->vendor);

        return response()->json(['message' => 'Rejected. The vendor has been told why.', 'data' => new PayoutDestinationResource($destination->fresh())]);
    }
}
