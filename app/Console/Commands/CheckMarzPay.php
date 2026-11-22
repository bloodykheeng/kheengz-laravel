<?php

namespace App\Console\Commands;

use App\Payments\GatewayUnavailable;
use App\Payments\MarzPay\MarzPayClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * One safe call to MarzPay with the keys in .env: the bank list (a read, no money moves). Run it the
 * day the keys are added on a machine, and after every key or server change: it says whether the keys
 * work and whether this server's IP is whitelisted, before a vendor or a buyer finds out
 * (workspace docs/marzpay.md §1).
 */
#[Signature('marzpay:check')]
#[Description('Check the MarzPay keys and this server\'s IP with one read (the bank list)')]
class CheckMarzPay extends Command
{
    public function handle(MarzPayClient $client): int
    {
        Cache::forget('marzpay:banks');

        try {
            $banks = $client->banks();
        } catch (GatewayUnavailable $e) {
            $this->error($e->getMessage());
            $this->line(match (true) {
                str_contains($e->getMessage(), 'not set up') => 'Copy the key and secret from MarzPay (Business account > API keys) into .env (docs/environment-setup.md).',
                str_contains($e->getMessage(), '401') => 'The key or secret is wrong, or not for this business account. Copy both again from MarzPay.',
                str_contains($e->getMessage(), '403') => 'This server\'s IP is not whitelisted. Add it in the MarzPay dashboard (bank transfers and payouts need it).',
                default => 'See docs/marzpay.md.',
            });

            return self::FAILURE;
        }

        $this->info('MarzPay answered with HTTP Basic auth: '.count($banks).' banks can be checked and paid.');

        return self::SUCCESS;
    }
}
