<?php

namespace App\Payments\MarzPay;

/** MarzPay's answer to "whose bank account is this?". */
final readonly class BankAccountCheck
{
    public function __construct(
        public bool $valid,
        /** The name the bank holds, when the account is valid. */
        public ?string $accountName,
        public ?string $bankCode,
        /** Why it is not valid, in the bank's words. */
        public ?string $reason,
    ) {}
}
