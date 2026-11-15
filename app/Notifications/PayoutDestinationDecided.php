<?php

namespace App\Notifications;

use App\Enums\PayoutDestinationStatus;
use App\Models\PayoutDestination;

/** To the vendor's Owners: the Admin approved or refused a payout destination. Emailed too: it is money. */
final class PayoutDestinationDecided extends EmailedNotification
{
    private string $vendorId;

    private string $label;

    private PayoutDestinationStatus $status;

    private ?string $reason;

    public function __construct(PayoutDestination $destination)
    {
        $this->vendorId = $destination->vendor_id;
        $this->label = $destination->label();
        $this->status = $destination->status;
        $this->reason = $destination->status_reason;
    }

    protected function kind(): string
    {
        return $this->status === PayoutDestinationStatus::Verified ? 'payout' : 'warning';
    }

    protected function title(): string
    {
        return $this->status === PayoutDestinationStatus::Verified ? 'Payout destination approved' : 'Payout destination not approved';
    }

    protected function body(): string
    {
        return $this->status === PayoutDestinationStatus::Verified
            ? "Money can now be paid to {$this->label}."
            : "{$this->label} will not be paid. The reason: {$this->reason}";
    }

    protected function link(): string
    {
        return '/dashboard/vendor';
    }

    protected function vendorId(): string
    {
        return $this->vendorId;
    }
}
