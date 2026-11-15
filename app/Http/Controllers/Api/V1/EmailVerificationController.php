<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\EmailVerification;
use App\Traits\RecordsUserLogins;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Confirming an email: the 6-digit code from the email. A correct code verifies the address and
 * signs the person in. Vendor sign-up (Phase 1) adds its welcome and the Admins' notice here.
 */
#[Group('Auth', 'Authentication endpoints.', weight: 1)]
class EmailVerificationController extends Controller
{
    use RecordsUserLogins;

    #[BodyParameter('email', 'The address the code was sent to.', required: true, type: 'string', example: 'brian@example.com')]
    #[BodyParameter('code', 'The 6-digit code.', required: true, type: 'string', example: '482931')]
    public function verify(Request $request, EmailVerification $verification): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'code' => ['required', 'digits:6'],
        ], ['code.digits' => 'Enter the 6-digit code from the email.']);

        $user = User::query()->firstWhere('email', $validated['email']);
        if (! $user) {
            throw ValidationException::withMessages(['code' => ['That code is not right, or it has expired. Send a new one.']]);
        }
        if ($user->email_verified_at) {
            throw ValidationException::withMessages(['email' => ['This email is already confirmed. Sign in instead.']]);
        }
        if (! $verification->verify($user, $validated['code'])) {
            throw ValidationException::withMessages(['code' => ['That code is not right, or it has expired. Send a new one.']]);
        }
        if ($user->status !== 'Active') {
            return response()->json(['message' => 'This account is not active. Please contact '.config('app.name').' support.'], 403);
        }

        $this->recordUserLogin($user);
        $token = $user->createToken('web')->plainTextToken;

        return response()->json([
            'message' => 'Email confirmed. Welcome to '.config('app.name').'!',
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => new UserResource($user->load('roles')),
        ]);
    }

    #[BodyParameter('email', 'The address to send a new code to.', required: true, type: 'string', example: 'brian@example.com')]
    public function resend(Request $request, EmailVerification $verification): JsonResponse
    {
        $validated = $request->validate(['email' => ['required', 'email', 'max:190']]);
        $user = User::query()->firstWhere('email', $validated['email']);

        // The same answer whether or not the address has an account, so nobody can probe for one.
        if ($user && ! $user->email_verified_at && ! $verification->send($user)) {
            return response()->json(['message' => 'We could not send the code just now. Try again in a minute, or write to '.config('mail.support_address').'.'], 503);
        }

        return response()->json(['message' => 'If that address is waiting to be confirmed, a new code is on its way. It works for '.EmailVerification::CODE_TTL_MINUTES.' minutes.']);
    }
}
