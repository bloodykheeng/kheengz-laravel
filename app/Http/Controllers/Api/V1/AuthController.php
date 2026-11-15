<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\EmailVerification;
use App\Traits\LoggableTrait;
use App\Traits\RecordsUserLogins;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

#[Group('Auth', 'Authentication endpoints.', weight: 1)]
class AuthController extends Controller
{
    use LoggableTrait, RecordsUserLogins;

    /**
     * Authenticate a user and issue an API token.
     *
     * Validates the credentials and returns a Sanctum bearer token
     * along with the authenticated user's data.
     */
    #[BodyParameter('email', 'The user\'s email address.', required: true, type: 'string', example: 'john@example.com')]
    #[BodyParameter('password', 'The user\'s password.', required: true, type: 'string', example: 'secret123')]
    #[BodyParameter('device_name', 'The device name for the token.', type: 'string', example: 'Postman')]
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            // Any of the three contact columns, and the field keeps its name
            // so the sign-in form's wire shape does not change.
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'device_name' => ['sometimes', 'string', 'max:255'],
            // `timezone` is deliberately NOT validated here. It is an optional
            // display preference the browser volunteers, so a stale or
            // unrecognised zone must never be able to fail a sign-in.
            // RecordsUserLogins checks it against PHP's IANA list and ignores
            // anything it does not recognise.
        ]);

        $user = User::findByCredential($request->input('email'));

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($user->status !== 'Active') {
            return response()->json(['message' => 'This account is not active. Please contact '.config('app.name').' support.'], 403);
        }

        // Signed up but never confirmed the email: send a fresh code and ask for it.
        if (! $user->email_verified_at && $user->email) {
            app(EmailVerification::class)->send($user);

            return response()->json([
                'message' => "Confirm your email to sign in. We sent a new 6-digit code to {$user->email}.",
                'code' => 'email_unverified',
                'data' => ['email' => $user->email],
            ], 403);
        }

        // Stamps lastlogin and the browser's timezone after the response is sent,
        // so the `lastlogin` returned below is the PREVIOUS sign-in.
        $this->recordUserLogin($user);

        $token = $user->createToken($request->input('device_name', 'api-token'))->plainTextToken;

        // Causer passed by hand: this route runs before authentication, so
        // Auth::user() is null and the entry would have no "Performed by".
        $this->logActivity('user_logged_in', "User '{$user->name}' logged in.", [], $user, null, $user);

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => new UserResource($user),
        ]);
    }

    /**
     * Revoke the authenticated user's current token.
     *
     * Logs out the user by deleting the token used for this request.
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        $this->logActivity('user_logged_out', "User '{$user->name}' logged out.", [], $user);

        $user->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }
}
