<?php

namespace App\Payments\MarzPay;

use App\Payments\GatewayUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Our own thin client for MarzPay's Merchant API (D6; workspace docs/marzpay.md). Only the calls a
 * step needs are here; collections and payouts join it with step 6, behind the PaymentGateway
 * interface. Every MarzPay call in the app goes through this class.
 *
 * Auth is HTTP Basic with the key and secret, as their OpenAPI spec says. Their bank-transfer page
 * shows an `X-API-Key` header instead: marked "Check" in docs/marzpay.md until the first live call.
 */
class MarzPayClient
{
    /**
     * The banks MarzPay can validate and pay into, each `{code, name, aliases}`. Cached for a day:
     * the list changes rarely and the bank picker reads it on every open.
     *
     * @return list<array{code: string, name: string, aliases: list<string>}>
     *
     * @throws GatewayUnavailable
     */
    public function banks(): array
    {
        return Cache::remember('marzpay:banks', now()->addDay(), function () {
            $banks = $this->send(fn (PendingRequest $http) => $http->get('/bank-transfer/banks'))->json('data.banks');

            if (! is_array($banks)) {
                throw new GatewayUnavailable('MarzPay answered the bank list without one.');
            }

            return array_values(array_map(fn (array $bank) => [
                'code' => (string) ($bank['code'] ?? ''),
                'name' => (string) ($bank['name'] ?? ''),
                'aliases' => array_values(array_map('strval', $bank['aliases'] ?? [])),
            ], $banks));
        });
    }

    /**
     * Ask MarzPay whose account this is. Valid only when MarzPay says so (status 0 and the account
     * active); a refused account comes back as an answer, not an exception. Only MarzPay being
     * unreachable, misconfigured or not offering bank transfers throws.
     *
     * @throws GatewayUnavailable
     */
    public function validateBankAccount(string $bankName, string $accountNumber): BankAccountCheck
    {
        $response = $this->send(
            fn (PendingRequest $http) => $http->post('/bank-transfer/validate', ['bank_name' => $bankName, 'account_number' => $accountNumber]),
            acceptClientErrors: true,
        );

        $data = (array) $response->json('data', []);
        $errorCode = (string) $response->json('error_code', '');

        if (in_array($errorCode, ['SERVICE_NOT_AVAILABLE', 'BANK_NOT_SUPPORTED'], true) || $response->status() === 401 || $response->status() === 403) {
            throw new GatewayUnavailable("MarzPay could not check bank accounts ({$response->status()} {$errorCode}).");
        }

        $valid = ($data['valid'] ?? false) === true;

        return new BankAccountCheck(
            valid: $valid,
            accountName: $valid ? trim((string) ($data['account_name'] ?? '')) : null,
            bankCode: isset($data['bank_code']) ? (string) $data['bank_code'] : null,
            reason: $valid ? null : (string) ($data['status_description'] ?? $response->json('message', 'The bank did not recognise this account.')),
        );
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     *
     * @throws GatewayUnavailable
     */
    private function send(callable $call, bool $acceptClientErrors = false): Response
    {
        $key = (string) config('services.marzpay.api_key');
        $secret = (string) config('services.marzpay.api_secret');

        if ($key === '' || $secret === '') {
            throw new GatewayUnavailable('MarzPay is not set up: MARZPAY_API_KEY and MARZPAY_API_SECRET are empty.');
        }

        $http = Http::baseUrl((string) config('services.marzpay.base_url'))
            ->withBasicAuth($key, $secret)
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('services.marzpay.timeout', 20));

        try {
            $response = $call($http);
        } catch (ConnectionException $e) {
            throw new GatewayUnavailable('MarzPay could not be reached: '.$e->getMessage(), previous: $e);
        }

        if ($response->serverError() || ($response->clientError() && ! $acceptClientErrors)) {
            throw new GatewayUnavailable("MarzPay answered {$response->status()}.");
        }

        return $response;
    }
}
