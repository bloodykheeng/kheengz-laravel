<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\VendorKind;
use App\Enums\VendorStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\VendorResource;
use App\Models\Vendor;
use App\Rules\NationalIdNumber;
use App\Rules\PhoneNumber;
use App\Services\VendorApprovals;
use App\Support\PerPage;
use App\Support\Phone;
use App\Traits\LoggableTrait;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Vendors: the Admin's list of every vendor and its decisions; a member's own vendor. Who may see
 * and change which vendor is VendorPolicy's call. docs/vendors-and-shops.md.
 */
#[Group('Vendors', 'Vendor applications, vendors, their documents and members.', weight: 3)]
class VendorController extends Controller
{
    use LoggableTrait;

    /** Reviewers: every vendor, filtered. A vendor's member: the vendors they work for. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        // Reviewers see every vendor; anyone else the vendors they work for.
        $visible = Vendor::query()->when(! $user->can('view vendors'), fn (Builder $q) => $q->whereHas('members', fn (Builder $m) => $m->whereKey($user->id)));

        $vendors = QueryBuilder::for($visible)
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('kind'),
                AllowedFilter::callback('search', fn (Builder $q, $value) => $q->where(fn (Builder $w) => $w
                    ->where('name', 'like', "%{$value}%")
                    ->orWhere('phone', 'like', '%'.preg_replace('/\D/', '', (string) $value).'%')
                    ->orWhere('email', 'like', "%{$value}%")
                    ->orWhere('national_id_number', 'like', '%'.strtoupper((string) $value).'%'))),
            )
            ->allowedSorts('name', 'status', 'created_at', 'approved_at')
            ->defaultSort('-created_at')
            ->allowedIncludes('createdBy', 'updatedBy', 'approvedBy')
            ->withCount('shops')
            ->with(['members' => fn ($m) => $m->whereKey($user->id)])
            ->paginate(PerPage::from($request))
            ->appends($request->query());

        return VendorResource::collection($vendors);
    }

    public function show(Request $request, Vendor $vendor): VendorResource
    {
        $this->authorize('view', $vendor);

        return new VendorResource($vendor->load(['documents', 'payoutDestinations', 'shops', 'members.staffShops', 'approvedBy', 'createdBy', 'updatedBy'])->loadCount('shops'));
    }

    /**
     * The Owner's own details. Contact details change any time; the identity the Admin checked (name,
     * kind, NIN, registration) only while the application is Pending or Rejected.
     */
    public function update(Request $request, Vendor $vendor): JsonResponse
    {
        $this->authorize('own', $vendor);

        $request->merge(array_filter([
            'phone' => $request->has('phone') ? (Phone::international($request->input('phone')) ?? $request->input('phone')) : null,
            'national_id_number' => $request->has('national_id_number') ? strtoupper((string) preg_replace('/\s+/', '', (string) $request->input('national_id_number'))) : null,
        ], fn ($v) => $v !== null));

        $kind = $request->input('kind', $vendor->kind->value);
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:160'],
            'kind' => ['sometimes', Rule::enum(VendorKind::class)],
            'national_id_number' => ['sometimes', 'string', new NationalIdNumber, Rule::unique('vendors', 'national_id_number')->ignore($vendor->id)],
            // Needed when the vendor is (or becomes) a company and none is on file yet.
            'registration_number' => [Rule::requiredIf($kind === VendorKind::Company->value && blank($vendor->registration_number)), 'nullable', 'string', 'max:40'],
            'phone' => ['sometimes', 'string', new PhoneNumber],
            'email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
        ], ['registration_number.required' => 'Enter the company registration number from URSB.']);

        if ($request->input('kind') === VendorKind::Individual->value) {
            $validated['registration_number'] = null;
        }

        // Once approved, the identity the Admin checked stays as checked.
        if (! in_array($vendor->status, [VendorStatus::Pending, VendorStatus::Rejected], true)) {
            $vendor->fill($validated);
            if ($vendor->isDirty(['name', 'kind', 'national_id_number', 'registration_number'])) {
                abort(422, 'Your name and ID details were checked when you were approved. Contact support to change them.');
            }
        }

        $before = $this->captureOriginal($vendor);
        $vendor->update([...$validated, 'updated_by' => $request->user()->id]);
        $this->logActivity('vendor_updated', "Vendor {$vendor->name} updated.", ['changes' => $this->buildChanges($vendor, $before)], $vendor);

        return response()->json(['message' => 'Saved.', 'data' => new VendorResource($vendor->fresh())]);
    }

    /** A rejected vendor who has corrected their details asks again. */
    public function resubmit(Request $request, Vendor $vendor, VendorApprovals $approvals): JsonResponse
    {
        $this->authorize('own', $vendor);
        $approvals->resubmit($vendor, $request->user());
        $this->logActivity('vendor_resubmitted', "{$vendor->name} sent their application again.", [], $vendor);

        return response()->json(['message' => 'Sent again. We will tell you as soon as it is decided.', 'data' => new VendorResource($vendor->fresh())]);
    }
}
