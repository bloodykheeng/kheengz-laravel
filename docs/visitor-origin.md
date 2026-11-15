# Where visitors come from

Workspace decision D29. What we record about the people using Kheengz (country, region, city, IP,
device, browser), where it is kept, and how the place is worked out.

## What is recorded today (the foundation)

Every audit entry (`activity_log.properties`, written by `App\Jobs\LogActivityJob` off the request
thread) carries:

| Key | From |
| --- | --- |
| `ip` | the request |
| `country`, `region`, `city`, `latitude`, `longitude`, `timezone` | the IP, through `stevebauman/location` |
| `user_agent`, `device`, `platform`, `platform_version`, `browser`, `browser_version` | the User-Agent, through `jenssegers/agent` |
| `created_by`, `created_by_email` | the signed-in person, when there is one |

That covers every create, update and delete, every sign-in (`user_logged_in`), sign-out and failed
action. The Activity Logs screen shows them; `tests/Feature/Api/AuthTest.php` proves a sign-in records
them.

## What the orders module adds (Phase 1)

Buyers have no account, so the audit trail alone would not let a report count sales by region. Each
**order** stores, as plain columns (no JSON, so reports and filters can count them):

`ip`, `country`, `region`, `city`, `device`, `platform`, `browser`, and **how the buyer arrived**:
`source` (the share link's source: WhatsApp, TikTok, Facebook, QR poster, direct) and `referrer`.

The same columns go on a vendor's sign-up. Reports: sales by country / region / city, by source, by
device. Where a place cannot be worked out the columns stay empty, never guessed.

## How the place is worked out

- **DB-IP City Lite**, a free database file read from our own disk by `App\Support\DbIpCityLite`
  (`config/location.php`). DB-IP publishes it in MaxMind's file format, so the class extends the package's
  MaxMind driver and changes only the download: one gzipped `.mmdb` a month from
  `download.db-ip.com/free/dbip-city-lite-YYYY-MM.mmdb.gz`, no account, no key.
- **Licence:** CC BY 4.0, so commercial use is allowed. The condition: every page that shows or uses these
  places (the activity log, sales by region reports) carries the link
  `<a href="https://db-ip.com">IP Geolocation by DB-IP</a>`. The dashboard footer holds it once the web
  foundation lands.
- **Setup per server:** `php artisan location:update` (about a minute; 120 MB into `database/geoip/`,
  git-ignored). The scheduler runs it on the 3rd of every month (`routes/console.php`); the file comes out
  early in the month, and if this month's is not out yet it takes last month's. A failed download keeps the
  file already installed. Until a file exists, places stay empty and nothing fails.
- **Accuracy:** country is reliable; region mostly; city is approximate, especially on MTN and Airtel, whose
  addresses move between towns. Reports group by country and region first. Places are never guessed.
- Private and local addresses (development, a LAN) are never looked up. A hit is cached for a day per IP, a
  miss for ten minutes.
- `LOCATION_TESTING` stays `false`: when on, the package places every local request at a Google address in
  the United States.

### What was ruled out

| Source | Why not |
| --- | --- |
| ip-api.com (the package's default; Alpha and GiftNest use it) | Free tier is non-commercial, 45 lookups a minute, plain HTTP, and every visitor's IP goes to them |
| MaxMind GeoLite | Free terms (EULA, 12 Feb 2026) cover internal use only; showing places to vendors or selling Kheengz needs the commercial licence, USD 10,000 a year for City |
| IPinfo Lite | Free and commercial, but country only: no region or city |
| Cloudflare location headers | Free when the site is behind Cloudflare, but they exist only on the live request, and the audit trail looks places up later on the queue. Worth adding for orders at deployment if we use Cloudflare |
| Paid lookup services (ipdata, ipapi.co, ...) | Cost per lookup and send the IP elsewhere |

Before going behind a proxy (Nginx, Cloudflare), set the trusted proxies so `$request->ip()` is the
visitor's address and not the proxy's.

## Privacy

IPs and places are personal data. The privacy policy says we record them, why (security, fraud,
delivery, reports) and for how long (the audit trail keeps ten years, `config/activitylog.php`).
Reports show places, never a buyer's IP.
