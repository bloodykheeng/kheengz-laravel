<?php

namespace App\Enums;

/**
 * One step in a shop's history (shop_reviews): the vendor sending it, the Admin's decision, a
 * suspension and its lifting, the vendor closing it.
 *
 * Stored as the string value, mirrored in the Next app's services/types/kheengz.ts.
 */
enum ShopReviewDecision: string
{
    case Submitted = 'Submitted';
    case Approved = 'Approved';
    case ChangesRequested = 'Changes requested';
    case Rejected = 'Rejected';
    case Suspended = 'Suspended';
    case Reinstated = 'Reinstated';
    case Closed = 'Closed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
