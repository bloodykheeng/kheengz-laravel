<?php

namespace App\Support;

use App\Enums\MobileNetwork;

/**
 * Phone numbers, stored one way everywhere: digits only with the country code and
 * no "+" (`256772123456`, `447911123456`).
 *
 * People type `0772 123 456`, `+256 772 123456` or `772123456`; storing one form
 * is what makes "one buyer per phone" and the SMS list's de-duplication
 * hold. Contact numbers may be from any country (`international()`); a Mobile
 * Money number must be a Ugandan mobile (`normalize()`), and SMS goes only to
 * Ugandan numbers (`isUgandan()`).
 */
final class Phone
{
    /** Prefixes after the leading 0, by network. */
    private const NETWORKS = [
        'MTN' => ['76', '77', '78', '79'],
        'Airtel' => ['70', '74', '75'],
    ];

    /** `0772123456` / `+256772123456` / `772123456` to `256772123456`; null if it is not a UG mobile. */
    public static function normalize(?string $input): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $input);

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '256')) {
            $local = substr($digits, 3);
        } elseif (str_starts_with($digits, '0')) {
            $local = substr($digits, 1);
        } else {
            $local = $digits;
        }

        return preg_match('/^7\d{8}$/', $local) ? '256'.$local : null;
    }

    public static function isValid(?string $input): bool
    {
        return self::normalize($input) !== null;
    }

    /**
     * Any country, digits only with the country code: `+44 7911 123456` or
     * `0044 7911123456` to `447911123456`. A Ugandan mobile may be typed the local
     * way. A foreign number without its country code is refused rather than
     * guessed. Null when it is not a dialable number.
     */
    public static function international(?string $input): ?string
    {
        $raw = trim((string) $input);

        if ($raw === '') {
            return null;
        }

        if ($ugandan = self::normalize($raw)) {
            return $ugandan;
        }

        $digits = preg_replace('/\D/', '', $raw);
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        // E.164: a country code that does not start with 0, 8 to 15 digits in all.
        return preg_match('/^[1-9]\d{7,14}$/', $digits) ? $digits : null;
    }

    /** A stored number on a Ugandan mobile network: the only numbers SMS is sent to. */
    public static function isUgandan(?string $stored): bool
    {
        return (bool) preg_match('/^2567\d{8}$/', (string) $stored);
    }

    /** `256772123456` to `0772123456`, how people read their own number; a foreign one keeps its `+`. */
    public static function local(?string $normalized): ?string
    {
        if (! $normalized) {
            return null;
        }

        return str_starts_with($normalized, '256') ? '0'.substr($normalized, 3) : '+'.$normalized;
    }

    /** The network a number most likely belongs to, from its prefix. The buyer may still choose. */
    public static function network(?string $input): ?MobileNetwork
    {
        $normalized = self::normalize($input);

        if (! $normalized) {
            return null;
        }

        $prefix = substr($normalized, 3, 2);

        foreach (self::NETWORKS as $network => $prefixes) {
            if (in_array($prefix, $prefixes, true)) {
                return MobileNetwork::from($network);
            }
        }

        return null;
    }

    /** `256772123456` to `256****456`, for screens a stranger might see. */
    public static function mask(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }

        return substr($phone, 0, 3).'****'.substr($phone, -3);
    }
}
