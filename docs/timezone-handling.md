# Timezone handling

Adopted from Alpha (`alpha-fund-laravel/docs/timezone-handling.md`), which took it from NICE. Read that
document for the full reasoning; these are the rules as they apply in Kheengz (copied from GiftNest).

1. **Store in UTC.** `config('app.timezone')` is `UTC` and stays `UTC`.
2. **The wire is UTC.** The API returns datetimes as ISO 8601 with a `Z`; the frontend sends
   `date.toISOString()`. Never send an offset such as `+03:00`: Eloquent keeps the wall-clock time and drops
   the offset, three hours wrong.
3. **One business timezone defines "a day"**: `config('app.business_timezone')` (`BUSINESS_TIMEZONE`, default
   `Africa/Kampala`), read only through `App\Support\BusinessDate`. Charts, statements and date filters use it.
4. **The viewer's timezone is for display only.**
5. **The MySQL session is pinned to UTC** (`'timezone' => env('DB_TIMEZONE', '+00:00')` on `mysql` and
   `mariadb` in `config/database.php`). `APP_TIMEZONE=UTC` alone is not enough: `TIMESTAMP` columns (every
   `timestamps()`) are converted through the session's zone, which otherwise is the database server's, so a
   server in Kampala would store and read three hours off. Check: `select @@session.time_zone` gives `+00:00`
   (coding-notes `ai-agents/dates-and-timezones-with-an-ai-agent.md`, trap 7).

## In code

- **No `date` columns.** Every date is `dateTime`. Money moments (an order's `placed_at`, a payout's `paid_at`, when
  those modules land) use the `App\Casts\Moment` cast: an instant is stored exactly, a bare
  `Y-m-d` becomes the first moment of that business day.
- **Filters** use `BusinessDate::rangeStartUtc()` / `rangeEndUtc()`: a bare day is the whole business day, an
  instant from a date-and-time picker is exact. Never `whereDate()`, which compares the UTC date.
- **Day buckets** (the 30-day charts) group by the business timezone: `BusinessDate::timezone()`, never a
  zone written in code.
- **Tests** pin the clock as a UTC instant: `Carbon::setTestNow(Carbon::parse('…', 'Africa/Kampala')->utc())`.
