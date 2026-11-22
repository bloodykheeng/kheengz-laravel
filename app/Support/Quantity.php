<?php

namespace App\Support;

/**
 * Quantities of stock in thousandths, as whole numbers: 1.5 kg is 1500. The columns are DECIMAL(12,3),
 * so three places is all there is; adding and comparing whole numbers never drifts the way floats do
 * (0.1 + 0.2), and no PHP extension is needed. docs/stock.md.
 */
final class Quantity
{
    public static function toMilli(string|int|float|null $quantity): int
    {
        return (int) round(((float) ($quantity ?? 0)) * 1000);
    }

    /** 1500 → "1.5", 12000 → "12": as a person writes it, and as the database takes it. */
    public static function fromMilli(int $milli): string
    {
        $sign = $milli < 0 ? '-' : '';
        $milli = abs($milli);
        $whole = intdiv($milli, 1000);
        $part = rtrim(str_pad((string) ($milli % 1000), 3, '0', STR_PAD_LEFT), '0');

        return $sign.$whole.($part === '' ? '' : ".{$part}");
    }

    /** "1.5" stays "1.5"; "2.000" becomes "2". */
    public static function tidy(string|int|float|null $quantity): string
    {
        return self::fromMilli(self::toMilli($quantity));
    }

    public static function isWhole(string|int|float $quantity): bool
    {
        return self::toMilli($quantity) % 1000 === 0;
    }
}
