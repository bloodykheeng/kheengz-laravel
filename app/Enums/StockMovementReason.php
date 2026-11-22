<?php

namespace App\Enums;

/**
 * Why a stock level changed (D33). The first five are a person's (received, counted, damaged,
 * returned, moved); the rest come from orders (held when placed, released when not paid or cancelled,
 * sold when sent). Counter sales join with step 7. docs/stock.md.
 *
 * Stored as the string value, mirrored in the Next app's services/types/catalogue.ts.
 */
enum StockMovementReason: string
{
    case Received = 'Received';
    case Counted = 'Counted';
    case Damaged = 'Damaged';
    case Returned = 'Returned';
    case MovedOut = 'Moved out';
    case MovedIn = 'Moved in';
    case Held = 'Held';
    case Released = 'Released';
    case SoldOnline = 'Sold online';

    /** What a shop's people record by hand on the stock screen (a move is recorded as both sides). */
    public static function manual(): array
    {
        return [self::Received, self::Counted, self::Damaged, self::Returned];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
