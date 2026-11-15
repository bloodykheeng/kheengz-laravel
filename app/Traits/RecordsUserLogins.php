<?php

namespace App\Traits;

use App\Models\User;

/**
 * Stamps a successful sign-in (users.lastlogin, plus the viewer's timezone)
 * WITHOUT paying for it in the login response: the write is deferred until after
 * the response has been sent, so signing in costs no extra database round trip.
 *
 * Same spirit as the deferred activity logging, but it runs after the response
 * rather than on a queue. A stamp this small should never depend on a worker
 * being alive to land, and the #1 recurring gotcha in this project is a stale
 * queue worker.
 *
 * A useful consequence: because the stamp happens after the response is built,
 * the `lastlogin` returned by login is the PREVIOUS sign-in, which is what
 * people actually mean by "last login".
 *
 * Used by every path that issues a token, so they cannot drift apart.
 */
trait RecordsUserLogins
{
    public function recordUserLogin(User $user): void
    {
        // Read the request now: it is gone by the time the deferred write runs.
        $timezone = request()->input('timezone');

        // The viewer's own zone, captured for DISPLAY only (rendering instants in
        // their local clock). Shared day boundaries always use
        // config('app.business_timezone'), never this. See docs/timezone-handling.md.
        //
        // Validated against PHP's IANA list rather than in the request rules: a
        // stale or unrecognised zone the browser volunteers must never be able to
        // fail somebody's sign-in.
        if (! is_string($timezone) || ! in_array($timezone, timezone_identifiers_list(), true)) {
            $timezone = null;
        }

        dispatch(function () use ($user, $timezone) {
            // array_filter drops a null timezone, so an absent one leaves the
            // existing value alone rather than blanking it.
            $user->update(array_filter([
                'lastlogin' => now(),
                'timezone' => $timezone,
            ]));
        })->afterResponse();
    }
}
