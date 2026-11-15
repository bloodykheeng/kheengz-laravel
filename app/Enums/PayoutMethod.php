<?php

namespace App\Enums;

/**
 * How a vendor is paid out (D12): to a Mobile Money number (through MarzPay or OlyCash) or to a bank
 * account (through MarzPay, which checks the account when it is saved).
 *
 * Stored as the string value, mirrored in the Next app's services/types/kheengz.ts.
 */
enum PayoutMethod: string
{
    case MobileMoney = 'Mobile Money';
    case Bank = 'Bank';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
