<?php

namespace App\Enums;

/**
 * Where a shop stands (D31). A vendor makes it as a Draft and sends it for review; the Admin
 * approves it (Active), asks for changes, or rejects it. Only an Active shop of an Approved vendor
 * sells. Suspended is the Admin's; Closed is the vendor's own choice. docs/vendors-and-shops.md.
 *
 * Stored as the string value, mirrored in the Next app's services/types/kheengz.ts.
 */
enum ShopStatus: string
{
    case Draft = 'Draft';
    case PendingReview = 'Pending review';
    case ChangesRequested = 'Changes requested';
    case Active = 'Active';
    case Rejected = 'Rejected';
    case Suspended = 'Suspended';
    case Closed = 'Closed';

    /** The vendor may still edit and (re)send it for review. */
    public function isEditableByVendor(): bool
    {
        return in_array($this, [self::Draft, self::ChangesRequested, self::Active], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
