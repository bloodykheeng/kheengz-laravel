<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Number;

/**
 * The page size a list endpoint serves, read from `per_page` and held to a ceiling.
 *
 * Every list is paginated, always: there is no "give me everything" switch. A
 * caller that needs every row (an Excel export, a dropdown's options) walks the
 * pages, so the server only ever holds one page. Carried over from Alpha's
 * docs/pagination-and-exports.md.
 */
final class PerPage
{
    /** Rows per page when the caller does not say: every list opens on five. */
    public const DEFAULT = 5;

    public const MAX = 100;

    public static function from(Request $request, int $default = self::DEFAULT, int $max = self::MAX): int
    {
        return (int) Number::clamp($request->integer('per_page', $default), 1, $max);
    }
}
