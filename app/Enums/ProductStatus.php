<?php

namespace App\Enums;

/**
 * Where a product stands. Draft: being written, never shown. Active: on sale whenever its shop is
 * selling. Hidden: taken off sale by the vendor, kept with its history (an Active product is hidden,
 * never deleted, because orders may point at it). docs/catalogue.md.
 *
 * Stored as the string value, mirrored in the Next app's services/types/catalogue.ts.
 */
enum ProductStatus: string
{
    case Draft = 'Draft';
    case Active = 'Active';
    case Hidden = 'Hidden';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
