<?php

namespace App\Notifications;

use App\Models\Vendor;

/** To the Admins: someone applied to sell, and their papers wait for a look. */
final class VendorApplied extends KheengzNotification
{
    private string $vendorId;

    private string $vendorName;

    public function __construct(Vendor $vendor)
    {
        // Plain values, not the model: the notice says what was true when it was sent.
        $this->vendorId = $vendor->id;
        $this->vendorName = $vendor->name;
    }

    protected function kind(): string
    {
        return 'vendor';
    }

    protected function title(): string
    {
        return 'New vendor application';
    }

    protected function body(): string
    {
        return "{$this->vendorName} applied to sell. Check their details and papers.";
    }

    protected function link(): string
    {
        return "/dashboard/vendors/{$this->vendorId}";
    }
}
