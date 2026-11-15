<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request a correlation id.
 *
 * One action can leave marks in three places: an audit entry, application log lines, and
 * a queued job's own logs. Without a shared id, tying them back together after an incident
 * means guessing from timestamps. The id is stored on the request (LoggableTrait copies it
 * onto the audit entry), pushed into Laravel's log context so every Log:: call in the
 * request carries it, and returned as a response header so a client can quote it in a
 * support ticket.
 *
 * An inbound X-Request-Id is honoured so a trace started at a proxy or the frontend
 * survives into the backend, but only when it looks sane: an attacker-supplied value
 * would otherwise poison the logs of unrelated requests.
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->header('X-Request-Id', '');

        $requestId = preg_match('/^[A-Za-z0-9\-]{8,64}$/', $incoming)
            ? $incoming
            : (string) Str::uuid();

        $request->attributes->set('request_id', $requestId);

        Log::shareContext(['request_id' => $requestId]);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
