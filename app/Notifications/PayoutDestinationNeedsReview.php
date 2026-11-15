<?php

namespace App\Notifications;

use App\Models\PayoutDestination;

/** To the Admins: a payout destination waits for them (its name differs, or it could not be checked). */
final class PayoutDestinationNeedsReview extends KheengzNotification
{
    private string $vendorId;

    private string $vendorName;

    private string $label;

    private string $reason;

    public function __construct(PayoutDestination $destination)
    {
        $this->vendorId = $destination->vendor_id;
        $this->vendorName = $destination->vendor->name;
        $this->label = $destination->label();
        $this->reason = (string) $destination->status_reason;
    }

    protected function kind(): string
    {
        return 'warning';
    }

    protected function title(): string
    {
        return 'Payout destination to check';
    }

    protected function body(): string
    {
        return "{$this->vendorName} added {$this->label}. {$this->reason}";
    }

    protected function link(): string
    {
        return "/dashboard/vendors/{$this->vendorId}";
    }

    protected function vendorId(): string
    {
        return $this->vendorId;
    }
}
