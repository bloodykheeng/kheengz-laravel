<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\OptionalAuthSanctum;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    // The SPA holds a Sanctum Bearer token, not a session cookie, so the channel
    // authorization endpoint has to run through the `auth:sanctum` guard and live
    // under /api/v1 with the rest of the API. Public channels never hit this route;
    // it is the presence channel (`online`) that needs it.
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        attributes: ['prefix' => 'api/v1', 'middleware' => ['api', 'auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Correlation id on every API request, so an audit entry can be lined up with
        // the application log lines it produced. See docs/audit-trail.md.
        $middleware->api(prepend: [
            AssignRequestId::class,
        ]);

        // Public routes that must still recognise a signed-in staff member.
        // GiftNest's docs/public-endpoints-and-optional-auth.md; Kheengz writes its own with the storefront.
        //
        // `permission` is Spatie's own, aliased here because Laravel's `can:`
        // takes exactly one ability and a route may legitimately accept either
        // of two. Spelling that as `can:a` plus a second check inside the
        // controller would put half the rule on the route and half out of sight.
        $middleware->alias([
            'optional_auth' => OptionalAuthSanctum::class,
            'permission' => PermissionMiddleware::class,
        ]);

        // A guest gets a 401, never a redirect. There is no `login` route on
        // this backend, the sign-in page being the Next app on another host, so
        // Laravel's default redirect would raise `RouteNotFoundException` and a
        // 500 at a browser, a `curl` or a monitoring probe. A null throws
        // `AuthenticationException` instead, which the handler below renders as
        // `{ "message": ... }` with a 401.
        $middleware->redirectGuestsTo(fn (Request $request) => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every handler below answers in Laravel's own error shape:
        //
        //   { "message": "...", "errors": { "field": ["..."] } }
        //
        // `message` is what a toast shows; `errors` is keyed by field so a form
        // can put each one under its input. Rendered explicitly rather than left
        // to the framework so an `api/*` request always gets JSON, whatever it
        // sent in its Accept header.
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'errors' => $e->errors(),
                ], 422);
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'You must be authenticated to access this resource.',
                ], 401);
            }
        });

        // A `restrict` foreign key makes the database throw when someone tries to
        // delete a record that is still referenced. Left alone that surfaces as a
        // raw 500; this turns it into a 409 the UI can show verbatim.
        //
        // Gating on DELETE was what made the message sniff safe: the only FK
        // violation a DELETE can raise is "this parent row is still referenced".
        // An insert/update FK failure is a different fault, a bad reference, and
        // keeps its normal handling. The string test covers MySQL (1451),
        // Postgres (23503) and SQLite (19), which all carry "foreign key".
        //
        // But a PUT can remove a parent row too: saving a criterion rewrites
        // its bands, so editing one that has been scored against raises 1451 on
        // a PUT. The method is therefore not the discriminator: MySQL and
        // Postgres both say in words which of the two failures this is, and
        // that phrase is what decides. DELETE stays as the fallback for SQLite,
        // which says only "FOREIGN KEY constraint failed" and cannot tell them
        // apart.
        $exceptions->render(function (QueryException $e, Request $request) {
            $message = strtolower($e->getMessage());
            $isForeignKeyViolation = str_contains($message, 'foreign key');

            // The one FK failure that means "still in use" rather than "points
            // at something that is not there".
            $isParentStillReferenced = str_contains($message, 'cannot delete or update a parent row')
                || str_contains($message, 'still referenced from table');

            if (
                $request->is('api/*')
                && $isForeignKeyViolation
                && ($request->isMethod('DELETE') || $isParentStillReferenced)
            ) {
                return response()->json([
                    'message' => 'This record is referenced by other records and cannot be deleted. Remove or reassign what points to it first.',
                ], 409);
            }
        });

        $exceptions->render(function (HttpException $e, Request $request) {
            if ($request->is('api/*')) {
                // A missing model arrives here as a 404 carrying Laravel's own
                // "No query results for model [App\Models\Group] 01kz...", which
                // names an internal class and reads like a stack trace. The
                // status already says what happened.
                $message = $e->getStatusCode() === 404
                    ? 'The requested record was not found.'
                    : ($e->getMessage() ?: 'Request failed.');

                return response()->json(['message' => $message], $e->getStatusCode());
            }
        });
    })->create();
