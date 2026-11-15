<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Turns a business-calendar date into the true UTC instants that bound it.
 *
 * Timestamps are stored in UTC, so a business day does not line up with a UTC day.
 * "1 July" in Kampala (UTC+3) actually runs from 2026-06-30 21:00:00 UTC to
 * 2026-07-01 20:59:59 UTC. Comparing a UTC datetime column with `whereDate()`
 * therefore silently uses UTC day-boundaries and drops the first three hours of
 * every local day into the day before.
 *
 * Every moment is stored in UTC, and every "which day is this" question (the day an
 * entry belongs to, the day's NAV, the day's interest, the month end) is asked on ONE
 * calendar: the business timezone, a setting (`BUSINESS_TIMEZONE`, config
 * app.business_timezone), never a zone written in code. The server's own timezone
 * plays no part, and each viewer still sees times on their own clock.
 *
 * A day-keyed column (one row per business day: an interest line, a valuation, a daily
 * rate) is stored as the first moment of that business day, in UTC. Ask for a day with
 * dayOf(); filter by day with the `whereBusinessDay` query scope (QueriesBusinessDays),
 * never with whereDate(), which reads the UTC date.
 *
 * The midnight snap always happens while the value still wears the business
 * timezone label; converting to UTC is the last step, never the first.
 */
class BusinessDate
{
    /**
     * The first instant of the given business day, expressed in UTC. A Carbon passed here
     * is read as a calendar date (its Y-m-d), the way dayOf() returns one.
     */
    public static function startOfDayUtc(string|DateTimeInterface $date): Carbon
    {
        return self::onBusinessCalendar($date)->startOfDay()->utc();
    }

    /** The last instant of the given business day, expressed in UTC. */
    public static function endOfDayUtc(string|DateTimeInterface $date): Carbon
    {
        return self::onBusinessCalendar($date)->endOfDay()->utc();
    }

    /**
     * A range filter's lower bound. A bare date (`2026-09-24`) means from the start of that
     * business day; a full instant (`2026-09-24T06:30:00.000Z`, what the client's
     * `toISOString()` sends) is taken exactly, so a range can narrow to hours within a day.
     */
    public static function rangeStartUtc(string $value): Carbon
    {
        return self::isInstant($value) ? Carbon::parse($value)->utc() : self::startOfDayUtc($value);
    }

    /** A range filter's upper bound: to the end of a bare date's business day, or up to an exact instant. */
    public static function rangeEndUtc(string $value): Carbon
    {
        return self::isInstant($value) ? Carbon::parse($value)->utc() : self::endOfDayUtc($value);
    }

    /**
     * The business-calendar day a moment falls on, as a plain date. A bare `Y-m-d` is that
     * day already; anything with a time (a stored UTC value, an instant from the client, a
     * Carbon) is read in UTC and turned onto the business calendar first.
     */
    public static function dayOf(string|DateTimeInterface $value): CarbonImmutable
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return CarbonImmutable::parse($value);
        }

        $instant = $value instanceof DateTimeInterface ? CarbonImmutable::instance($value) : CarbonImmutable::parse($value, 'UTC');

        return CarbonImmutable::parse($instant->setTimezone(self::timezone())->toDateString());
    }

    /**
     * A request's day (a `Y-m-d`, or an exact moment from a date-and-time picker) as the
     * business day it names, ready for day arithmetic.
     */
    public static function parseDay(string $value): Carbon
    {
        return Carbon::parse(self::dayOf($value)->toDateString());
    }

    /**
     * The last whole second of a business day, in UTC: the moment a day-end entry (a day's
     * interest) is stamped with. Whole seconds, because a DATETIME column rounds a
     * fraction up into the next day.
     */
    public static function endOfDayStamp(string|DateTimeInterface $date): Carbon
    {
        return self::endOfDayUtc($date)->startOfSecond();
    }

    /** The current business-calendar date (what "today" means to the business). */
    public static function today(): Carbon
    {
        return Carbon::now(self::timezone())->startOfDay();
    }

    /**
     * Today's business date as a plain calendar date with no zone attached, for day
     * arithmetic against stored dates: mixing zoned and naive instants silently drops
     * the last day of a range.
     */
    public static function calendarToday(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::today()->toDateString());
    }

    /** The configured business timezone: the one calendar shared reports use. */
    public static function timezone(): string
    {
        return config('app.business_timezone');
    }

    /**
     * The given calendar date, wearing the business timezone label so that a
     * midnight snap lands on the business day rather than the UTC one.
     */
    private static function isInstant(string $value): bool
    {
        return str_contains($value, 'T');
    }

    private static function onBusinessCalendar(string|DateTimeInterface $date): Carbon
    {
        $value = $date instanceof DateTimeInterface ? $date->format('Y-m-d') : $date;

        return Carbon::parse($value, self::timezone());
    }
}
