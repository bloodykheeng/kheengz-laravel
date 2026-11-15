<?php

namespace App\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * How hard buyers may use the public storefront API (docs/rate-limits.md). From GiftNest.
 *
 * Most buyers here are on MTN or Airtel, which put many handsets behind one public address
 * (carrier-grade NAT), and a busy market's or office's Wi-Fi puts everyone behind one. A limit per
 * IP treats them all as one person: a flash sale is exactly when it would trip, for everybody at
 * once. So each limit is per PERSON where the request says who it is (the phone number typed,
 * the order's reference, a code's token), and per IP only as a high ceiling against one machine
 * flooding the API.
 *
 *   shop-reads     shop pages, products, search: per IP, high
 *   order-follow   one order or payment being watched (the screen polls while a prompt waits):
 *                  per reference, plus the IP ceiling
 *   buyer-writes   placing an order, paying, retrying, confirming a code: per person, plus the
 *                  IP ceiling
 *   otp-codes      sending a code by SMS (tracking an order, saving details): per number, plus
 *                  the IP ceiling; each costs an SMS
 */
final class BuyerRateLimits
{
    /** Per minute. A crowded network sharing one address is still far below these. */
    public const READS_PER_IP = 6000;

    public const WRITES_PER_IP = 600;

    public const CODES_PER_IP = 120;

    /** Per minute, for one person or one reference. */
    public const WRITES_PER_PERSON = 10;

    public const FOLLOWS_PER_REFERENCE = 120;

    public const CODES_PER_PERSON = 3;

    public static function register(): void
    {
        RateLimiter::for('shop-reads', fn (Request $request) => self::perIp('shop-reads', $request, self::READS_PER_IP));

        RateLimiter::for('order-follow', fn (Request $request) => [
            Limit::perMinute(self::FOLLOWS_PER_REFERENCE)->by('order-follow|'.self::who($request))->response(self::busy(...)),
            self::perIp('order-follow', $request, self::READS_PER_IP),
        ]);

        RateLimiter::for('buyer-writes', fn (Request $request) => [
            Limit::perMinute(self::WRITES_PER_PERSON)->by('buyer-writes|'.self::who($request))->response(self::slowDown(...)),
            self::perIp('buyer-writes', $request, self::WRITES_PER_IP),
        ]);

        RateLimiter::for('otp-codes', fn (Request $request) => [
            Limit::perMinute(self::CODES_PER_PERSON)->by('otp-codes|'.self::who($request))->response(self::slowDown(...)),
            self::perIp('otp-codes', $request, self::CODES_PER_IP),
        ]);
    }

    /**
     * Who is asking, as the request says: a phone number typed (the buyer's own, or the one the
     * prompt goes to), else an email typed, else the order's reference, else a code's token.
     * The IP only when the request names nobody.
     */
    public static function who(Request $request): string
    {
        foreach (['phone', 'payment_phone'] as $field) {
            $digits = preg_replace('/\D/', '', (string) $request->input($field));
            if (strlen((string) $digits) >= 9) {
                return 'phone:'.substr((string) $digits, -9);
            }
        }

        $email = mb_strtolower(trim((string) $request->input('email')));
        if (str_contains($email, '@')) {
            return 'email:'.$email;
        }

        foreach (['reference', 'token'] as $parameter) {
            $value = $request->route($parameter);
            if (is_string($value) && $value !== '') {
                return "{$parameter}:{$value}";
            }
        }

        return 'ip:'.$request->ip();
    }

    private static function perIp(string $name, Request $request, int $perMinute): Limit
    {
        return Limit::perMinute($perMinute)->by("{$name}-ip|".$request->ip())->response(self::busy(...));
    }

    /** One person going too fast: a plain sentence, as every other refusal. */
    private static function slowDown(Request $request, array $headers): JsonResponse
    {
        return response()->json(['message' => 'That was a lot of tries in a short time. Please wait a minute and try again.'], 429, $headers);
    }

    /** The ceiling for one address: more likely a crowd than an attack. */
    private static function busy(Request $request, array $headers): JsonResponse
    {
        return response()->json(['message' => 'The shop is very busy right now. Please try again in a few seconds.'], 429, $headers);
    }
}
