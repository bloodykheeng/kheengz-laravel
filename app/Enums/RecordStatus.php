<?php

namespace App\Enums;

/**
 * On or off, for reference data and products.
 *
 * Stored as the string value, mirrored in the Next app's lib/enums.ts.
 */
enum RecordStatus: string
{
    case Active = 'Active';
    case Inactive = 'Inactive';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
