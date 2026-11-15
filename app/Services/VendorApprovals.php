<?php

namespace App\Services;

use App\Enums\VendorDocumentType;
use App\Enums\VendorKind;
use App\Enums\VendorStatus;
use App\Jobs\SendSmsJob;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\VendorDecided;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * A vendor's status (D35), only by legal moves, each told to the vendor's Owners (bell, email) and
 * by SMS. Suspending a vendor stops every one of its shops selling (Shop::isSelling) without touching
 * the shops' own statuses, so reinstating brings each back exactly as it was. docs/vendors-and-shops.md.
 */
class VendorApprovals
{
    /**
     * The papers a vendor must have on file before approval: the owner's ID, both sides, and for a
     * company its registration.
     *
     * @return list<VendorDocumentType>
     */
    public static function requiredDocuments(Vendor $vendor): array
    {
        return [
            VendorDocumentType::NationalIdFront,
            VendorDocumentType::NationalIdBack,
            ...($vendor->kind === VendorKind::Company ? [VendorDocumentType::BusinessRegistration] : []),
        ];
    }

    /** @return list<VendorDocumentType> */
    public static function missingDocuments(Vendor $vendor): array
    {
        $have = $vendor->documents()->pluck('type')->map(fn ($type) => $type instanceof VendorDocumentType ? $type : VendorDocumentType::from($type));

        return array_values(array_filter(self::requiredDocuments($vendor), fn (VendorDocumentType $type) => ! $have->contains($type)));
    }

    public function approve(Vendor $vendor, User $by): void
    {
        $missing = self::missingDocuments($vendor);
        if ($missing !== []) {
            throw ValidationException::withMessages(['status' => 'Still missing: '.implode(', ', array_map(fn ($t) => $t->value, $missing)).'.']);
        }

        $this->move($vendor, [VendorStatus::Pending], VendorStatus::Approved, null, $by, ['approved_at' => now(), 'approved_by' => $by->id]);
        $this->tell($vendor, "{$vendor->name}: your vendor account is approved. Your shops can open once each is reviewed.");
    }

    public function reject(Vendor $vendor, string $reason, User $by): void
    {
        $this->move($vendor, [VendorStatus::Pending], VendorStatus::Rejected, $reason, $by);
        $this->tell($vendor, "{$vendor->name}: your vendor application was not approved: {$reason}");
    }

    public function suspend(Vendor $vendor, string $reason, User $by): void
    {
        $this->move($vendor, [VendorStatus::Approved], VendorStatus::Suspended, $reason, $by);
        $this->tell($vendor, "{$vendor->name}: your vendor account is suspended and your shops are closed for now: {$reason}");
    }

    public function reinstate(Vendor $vendor, User $by): void
    {
        $this->move($vendor, [VendorStatus::Suspended], VendorStatus::Approved, null, $by);
        $this->tell($vendor, "{$vendor->name}: your vendor account is active again.");
    }

    /** A rejected vendor who has corrected their details asks again. */
    public function resubmit(Vendor $vendor, User $by): void
    {
        $this->move($vendor, [VendorStatus::Rejected], VendorStatus::Pending, null, $by);
    }

    /**
     * @param  list<VendorStatus>  $from
     * @param  array<string, mixed>  $extra
     */
    private function move(Vendor $vendor, array $from, VendorStatus $to, ?string $reason, User $by, array $extra = []): void
    {
        DB::transaction(function () use ($vendor, $from, $to, $reason, $by, $extra) {
            $current = Vendor::query()->whereKey($vendor->id)->lockForUpdate()->firstOrFail();

            if (! in_array($current->status, $from, true)) {
                throw ValidationException::withMessages(['status' => "This vendor is {$current->status->value} now, so that cannot be done."]);
            }

            $vendor->forceFill([...$extra, 'status' => $to, 'status_reason' => $reason, 'updated_by' => $by->id])->save();
        });
    }

    private function tell(Vendor $vendor, string $sms): void
    {
        Notification::send($vendor->members()->wherePivot('role', 'Owner')->get(), new VendorDecided($vendor));
        SendSmsJob::dispatch($vendor->phone, $sms);
    }
}
