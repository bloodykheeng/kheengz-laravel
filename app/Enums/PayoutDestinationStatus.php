<?php

namespace App\Enums;

/**
 * Whether money may go to a payout destination (D31). Verified: the name on the account matches
 * the vendor or one of its Owners (and a bank account was confirmed by MarzPay). Awaiting review:
 * the names differ, or the account could not be checked, so the Admin decides. Rejected: the Admin
 * refused it; it is never paid. docs/vendors-and-shops.md "Payout destinations".
 *
 * Stored as the string value, mirrored in the Next app's services/types/kheengz.ts.
 */
enum PayoutDestinationStatus: string
{
    case Verified = 'Verified';
    case AwaitingReview = 'Awaiting review';
    case Rejected = 'Rejected';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
