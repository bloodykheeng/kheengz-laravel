<?php

namespace App\Enums;

/**
 * The Mobile Money networks a prompt can go to.
 *
 * Stored as the string value, mirrored in the Next app's lib/enums.ts.
 */
enum MobileNetwork: string
{
    case Mtn = 'MTN';
    case Airtel = 'Airtel';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
