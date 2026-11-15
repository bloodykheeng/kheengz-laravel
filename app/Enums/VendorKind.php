<?php

namespace App\Enums;

/**
 * Who the vendor is in law: a person trading in their own name, or a registered company (which then
 * gives its registration number).
 *
 * Stored as the string value, mirrored in the Next app's services/types/kheengz.ts.
 */
enum VendorKind: string
{
    case Individual = 'Individual';
    case Company = 'Company';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
