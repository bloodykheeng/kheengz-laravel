<?php

namespace App\Jobs;

use App\Services\SmsSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * One SMS to one person, outside any campaign: an order confirmed, a payout sent.
 * Queued so a slow gateway never holds the Admin's request.
 */
class SendSmsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(
        public readonly string $phone,
        public readonly string $message,
    ) {}

    public function handle(SmsSender $sms): void
    {
        $result = $sms->send($this->phone, $this->message);

        if (! $result['ok']) {
            Log::info('SMS not sent', ['phone' => $this->phone, 'response' => $result['response']]);
        }
    }
}
