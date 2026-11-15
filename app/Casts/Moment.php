<?php

namespace App\Casts;

use App\Support\BusinessDate;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A moment column: stored in UTC, whatever is written.
 *
 *   an instant (`2026-07-10T06:30:00Z`, a Carbon)  stored exactly, in UTC
 *   a bare day (`2026-07-10`)                       the first moment of that BUSINESS day
 *
 * The second rule is what keeps a date typed without a time on the right day whatever the
 * business timezone is: parsed as-is, `2026-07-10` would be UTC midnight, which is still
 * 9 July for a business calendar behind UTC. Reads back as a UTC Carbon; ask for its day
 * with BusinessDate::dayOf().
 *
 * @implements CastsAttributes<Carbon, string|DateTimeInterface>
 */
class Moment implements CastsAttributes
{
    /** @param  array<string, mixed>  $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value, 'UTC');
    }

    /** @param  array<string, mixed>  $attributes */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->utc()->format('Y-m-d H:i:s');
        }

        $value = (string) $value;

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
            ? BusinessDate::startOfDayUtc($value)->format('Y-m-d H:i:s')
            : Carbon::parse($value, 'UTC')->utc()->format('Y-m-d H:i:s');
    }
}
