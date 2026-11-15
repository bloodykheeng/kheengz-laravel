# OTPs and Short-Lived Secrets

One-time codes live in the **cache**, never in a database table.

---

## 1. The rule

> A secret that expires in minutes does not belong in a table you have to clean up.

`Cache::put($key, $otp, now()->addMinutes(10))` gives expiry for free. There is no
row to purge, no scheduled cleanup command to forget to run, and no table quietly
accumulating every reset code anyone has ever been sent.

```php
// Issue
Cache::put($this->otpKey($user->id), $otp, now()->addMinutes(self::OTP_TTL_MINUTES));

// Verify (constant time — never `==`)
$cached = Cache::get($this->otpKey($user->id));
$ok = is_string($cached) && hash_equals($cached, $submitted);

// Burn after a successful reset
Cache::forget($this->otpKey($user->id));
```

Writing the key again replaces the previous code, so **only the most recent
request is ever valid** — no extra "invalidate the old ones" query.

---

## 2. Why not a table

The project originally had an `otp_requests` table. It was removed because:

- **Cleanup never happens.** Expiry was checked in PHP
  (`created_at->addMinutes(10)->isPast()`), so expired rows were only deleted if
  someone happened to submit them. Codes nobody used stayed forever.
- **It stores live secrets at rest.** Plaintext reset codes in a table are a
  standing disclosure risk — a database dump or read-only report access is enough.
- **Expiry logic drifts.** A TTL is one argument the cache enforces; a manual
  `isPast()` check has to be repeated correctly in every branch that reads the code
  (the old controller had it in two places, and skipping it in a third would have
  silently accepted expired codes).
- **It buys nothing.** Nothing else queried the table, and a lost code costs one
  more "resend" click.

---

## 3. Cache store

`CACHE_STORE=database` in development, so codes land in the `cache` table with a
real expiry the driver honours. This is not the same as hand-rolling a table:
the store handles expiry, replacement and eviction, and swapping to Redis in
production is an env change with no code touched.

**One consequence:** `php artisan cache:clear` invalidates pending OTPs. That is
correct behaviour — users just request a new code — but it is worth knowing when a
password reset "stops working" right after a deploy that cleared the cache.

---

## 4. Where this applies

| Flow | Key | TTL |
| --- | --- | --- |
| Password reset (`auth/otp/*`) | `pwreset_otp_{userId}` | 10 minutes |

The reset code is emailed, and also texted when the account's phone is a Ugandan number (SMS goes to
Ugandan numbers only), so someone with a phone and no email can still reset. The response
says where it went; when neither could be sent it answers 503 with the support address.

Anything added later that issues a short-lived code — login OTP, email
verification, an invite token — follows the same pattern: a namespaced cache key,
an explicit TTL, `hash_equals` to compare, `forget` once consumed.

---

## 5. Related: credential lookup

`OtpPasswordResetController::userFor()` picks the column from the credential's
shape — an email is matched against `email`, a phone number against `phone`.

The earlier version used `where('email', $input)->orWhere('phone', $input)`, which
searches both columns for the same value. That is worth avoiding on an
unauthenticated endpoint: it lets a value stored in the wrong column resolve to an
account, and the `orWhere` without grouping is a familiar source of precedence bugs
once another condition is added.
