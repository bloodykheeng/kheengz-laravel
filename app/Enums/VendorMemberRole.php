<?php

namespace App\Enums;

/**
 * Someone's place in a vendor (D26). An Owner runs every shop of the vendor and its money, members
 * and documents; Staff run only the shops they are given. Never an account role.
 *
 * Stored as the string value, mirrored in the Next app's services/types/kheengz.ts.
 */
enum VendorMemberRole: string
{
    case Owner = 'Owner';
    case Staff = 'Staff';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
