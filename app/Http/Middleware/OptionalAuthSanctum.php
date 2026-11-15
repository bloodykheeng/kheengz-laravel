<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optional Sanctum authentication for PUBLIC routes.
 *
 * Some endpoints are public, the landing page reads FAQs without logging in,
 * yet the SAME endpoint is called by the dashboard, where a signed-in staff
 * member must see more than a guest (inactive records as well as active ones).
 *
 * On a route with no auth middleware Laravel never resolves the Bearer token, so
 * `$request->user()` is null even for staff. Any "guests only see Active" scope
 * then fires for staff too, and the dashboard quietly hides rows it should show.
 *
 * Unlike `auth:sanctum`, this NEVER rejects a request: a guest continues with no
 * user. When a valid Bearer token IS present it resolves the user onto the
 * default guard, so controllers keep using the idiomatic `$request->user()`.
 */
class OptionalAuthSanctum
{
    public function handle(Request $request, Closure $next): Response
    {
        // Resolve via the sanctum guard. A guest resolves to null and passes
        // straight through. Always calling the guard: rather than gating on a
        // raw bearer token being present: also honours Sanctum::actingAs() in
        // tests, which sets the guard user without sending a header.
        $user = Auth::guard('sanctum')->user();

        if ($user !== null) {
            Auth::setUser($user);
        }

        return $next($request);
    }
}
