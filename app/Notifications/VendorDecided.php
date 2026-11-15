<?php

namespace App\Notifications;

use App\Enums\VendorStatus;
use App\Models\Vendor;

/** To the vendor's Owners: approved, not approved, suspended or active again. In the bell and by email. */
final class VendorDecided extends EmailedNotification
{
    private string $vendorName;

    private VendorStatus $status;

    private ?string $reason;

    public function __construct(Vendor $vendor)
    {
        $this->vendorName = $vendor->name;
        $this->status = $vendor->status;
        $this->reason = $vendor->status_reason;
    }

    protected function kind(): string
    {
        return $this->status === VendorStatus::Approved ? 'vendor' : 'warning';
    }

    protected function title(): string
    {
        return match ($this->status) {
            VendorStatus::Approved => 'Your vendor account is approved',
            VendorStatus::Rejected => 'Your vendor application was not approved',
            VendorStatus::Suspended => 'Your vendor account is suspended',
            VendorStatus::Pending => 'Your vendor application is waiting for review',
        };
    }

    protected function body(): string
    {
        return match ($this->status) {
            VendorStatus::Approved => "{$this->vendorName} is verified. Each of your shops opens once it is reviewed.",
            VendorStatus::Rejected => "The reason: {$this->reason} Correct your details and send them again.",
            VendorStatus::Suspended => "Your shops are closed for now. The reason: {$this->reason}",
            VendorStatus::Pending => 'We will tell you as soon as it is decided.',
        };
    }

    protected function link(): string
    {
        return '/dashboard/vendor';
    }
}
