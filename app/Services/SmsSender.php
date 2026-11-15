<?php

namespace App\Services;

use App\Support\Phone;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends one SMS and says plainly whether it went.
 *
 * Callers record the result on `sms_messages`; this class only talks to the
 * gateway. Credentials come from config, never env(), which returns null once
 * `config:cache` has run and would post every message to nowhere.
 */
class SmsSender
{
    /** @return array{ok: bool, response: string} */
    public function send(string $phone, string $message): array
    {
        // The gateway is for Ugandan numbers; anyone abroad gets email and the screen.
        if (! Phone::isUgandan($phone)) {
            return ['ok' => false, 'response' => 'Not sent: SMS goes to Ugandan numbers only.'];
        }

        if (config('services.sms.driver') === 'log') {
            Log::channel(config('logging.default'))->info("[SMS to {$phone}] {$message}");

            return ['ok' => true, 'response' => 'Logged (SMS_DRIVER=log), not sent to a phone.'];
        }

        $url = config('services.sms.url');

        if (blank($url)) {
            return ['ok' => false, 'response' => 'SMS gateway is not configured.'];
        }

        $query = array_filter([
            'message' => $message,
            'recipient' => $phone,
            'account' => config('services.sms.account_id'),
            'authorization' => config('services.sms.auth_code'),
            'sender' => config('services.sms.sender'),
        ], fn ($value) => filled($value));

        try {
            $response = Http::timeout(20)->get($url, $query);

            return [
                'ok' => $response->successful(),
                'response' => mb_substr($response->status().' '.$response->body(), 0, 1000),
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'response' => mb_substr($e->getMessage(), 0, 1000)];
        }
    }
}
