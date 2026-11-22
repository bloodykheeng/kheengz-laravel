<?php

namespace App\Enums;

/**
 * Where a vendor's request for a missing category stands (D32). Added: the Admin made it, or pointed
 * to one that already fits. Declined: with the Admin's reason. docs/catalogue.md.
 *
 * Stored as the string value, mirrored in the Next app's services/types/catalogue.ts.
 */
enum CategoryRequestStatus: string
{
    case Pending = 'Pending';
    case Added = 'Added';
    case Declined = 'Declined';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
