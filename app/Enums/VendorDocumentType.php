<?php

namespace App\Enums;

/**
 * The papers a vendor gives for approval (D35): the owner's national ID, both sides, and for a
 * company its certificate of incorporation or business registration.
 *
 * Stored as the string value, mirrored in the Next app's services/types/kheengz.ts.
 */
enum VendorDocumentType: string
{
    case NationalIdFront = 'National ID (front)';
    case NationalIdBack = 'National ID (back)';
    case BusinessRegistration = 'Business registration';
    case Other = 'Other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
