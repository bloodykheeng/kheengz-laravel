<?php

namespace App\Enums;

/**
 * Where a vendor (the person or business behind one or more shops) stands with the platform.
 * Approved once by the Admin (D35); suspending a vendor closes all their shops. docs/vendors-and-shops.md.
 *
 * Stored as the string value, mirrored in the Next app's services/types/kheengz.ts.
 */
enum VendorStatus: string
{
    case Pending = 'Pending';
    case Approved = 'Approved';
    case Rejected = 'Rejected';
    case Suspended = 'Suspended';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
