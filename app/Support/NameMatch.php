<?php

namespace App\Support;

/**
 * Whether the name on a payout account belongs to the vendor (D31): banks print "KATO FAHIM" for a
 * man the vendor calls Fahim Kato, and "HOME NEST LIMITED" for "Home Nest Ltd". So names are
 * compared as sets of words: case, punctuation, word order and company suffixes do not count, and
 * every word of the shorter name must be in the longer one, with at least two words in common (one
 * only when both names are a single word: "FAHIM" alone is too many people to match Fahim Kato).
 * Anything else waits for the Admin, never refused.
 */
final class NameMatch
{
    /** Words that say what kind of business, not whose. */
    private const SUFFIXES = ['ltd', 'limited', 'co', 'company', 'enterprises', 'enterprise', 'smc', 'u', 'ug', 'uganda', 'and', 'the', 'of'];

    /** @param  list<string>  $candidates  the vendor's name and its Owners' names */
    public static function belongsToAny(string $accountName, array $candidates): bool
    {
        foreach ($candidates as $candidate) {
            if (self::same($accountName, $candidate)) {
                return true;
            }
        }

        return false;
    }

    public static function same(string $a, string $b): bool
    {
        $wordsA = self::words($a);
        $wordsB = self::words($b);

        if ($wordsA === [] || $wordsB === []) {
            return false;
        }

        [$shorter, $longer] = count($wordsA) <= count($wordsB) ? [$wordsA, $wordsB] : [$wordsB, $wordsA];
        $common = array_intersect($shorter, $longer);

        return count($common) === count($shorter) && count($common) >= min(2, count($longer));
    }

    /** @return list<string> distinct words, lower-case, without suffixes */
    private static function words(string $name): array
    {
        $plain = strtolower((string) preg_replace('/[^\pL\pN]+/u', ' ', $name));
        $words = array_filter(explode(' ', $plain), fn (string $w) => $w !== '' && ! in_array($w, self::SUFFIXES, true));

        return array_values(array_unique($words));
    }
}
