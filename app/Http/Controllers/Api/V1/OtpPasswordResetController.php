<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SmsSender;
use App\Traits\LoggableTrait;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Group;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

#[Group('Password Reset', 'OTP-based password reset flow.', weight: 2)]
class OtpPasswordResetController extends Controller
{
    use LoggableTrait;

    /** How long a reset code stays usable. */
    private const OTP_TTL_MINUTES = 10;

    /**
     * Request a password reset OTP.
     *
     * Accepts an email address or phone number, finds the matching user,
     * and sends a 6-digit OTP via email and/or SMS.
     */
    #[BodyParameter('email', 'Email address or phone number of the account.', required: true, type: 'string', example: 'john@example.com')]
    public function requestOtp(Request $request, SmsSender $sms): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'max:255'],
        ]);

        $user = $this->userFor($request->input('email'));

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'No account found with that email or phone number.',
            ], 404);
        }

        $otp = (string) random_int(100000, 999999);

        // OTPs are short-lived secrets, so they live in the cache rather than a
        // database table: the TTL expires them automatically, nothing has to be
        // cleaned up, and a used or stale code leaves no row behind to leak.
        // Writing the key also replaces any previous code for this user, so only
        // the most recent request is ever valid.
        Cache::put($this->otpKey($user->id), $otp, now()->addMinutes(self::OTP_TTL_MINUTES));

        $emailSent = false;

        if ($user->email) {
            try {
                Mail::send('emails.password-reset.password_reset_otp', ['otp' => $otp, 'user' => $user, 'minutes' => self::OTP_TTL_MINUTES], function ($message) use ($user) {
                    $message->to($user->email)->subject('Your '.config('app.name').' password reset code');
                });
                $emailSent = true;
            } catch (Exception $e) {
                $this->logActivity('otp_email_failed', "Failed to send OTP email to '{$user->email}': {$e->getMessage()}", [], $user, null, $user);
            }
        }

        // Someone with a phone and no email would otherwise never get the code.
        $smsSent = false;
        if ($user->phone) {
            $result = $sms->send($user->phone, 'Your '.config('app.name')." reset code is {$otp}. It works for ".self::OTP_TTL_MINUTES.' minutes. Never share it; GiftNest will not ask you for it.');
            $smsSent = (bool) ($result['ok'] ?? false);
        }

        $this->logActivity('otp_requested', "OTP requested for user '{$user->email}'.", [
            'email_sent' => $emailSent,
            'sms_sent' => $smsSent,
        ], $user, null, $user);

        if (! $emailSent && ! $smsSent) {
            return response()->json([
                'success' => false,
                'message' => 'We could not send a reset code to this account. Write to '.config('mail.support_address').' for help.',
            ], 503);
        }

        $where = match (true) {
            $emailSent && $smsSent => 'your email and phone',
            $emailSent => 'your email',
            default => 'your phone',
        };

        return response()->json([
            'success' => true,
            'message' => "A reset code has been sent to {$where}. It works for ".self::OTP_TTL_MINUTES.' minutes.',
            'data' => ['email_sent' => $emailSent, 'sms_sent' => $smsSent],
        ]);
    }

    /**
     * Validate a password reset OTP.
     *
     * Verifies the OTP is valid and has not expired.
     */
    #[BodyParameter('email', 'Email address or phone number used when requesting OTP.', required: true, type: 'string', example: 'john@example.com')]
    #[BodyParameter('otp', 'The 6-digit OTP code.', required: true, type: 'string', example: '482931')]
    public function validateOtp(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'otp' => ['required', 'string', 'size:6'],
        ]);

        $user = $this->userFor($request->input('email'));

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'No account found.'], 404);
        }

        if (! $this->otpMatches($user, (string) $request->input('otp'))) {
            return response()->json([
                'success' => false,
                'message' => 'The OTP is invalid or has expired. Please request a new one.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'OTP validated successfully.',
            'data' => ['email' => $user->email],
        ]);
    }

    /**
     * Reset password using OTP.
     *
     * Validates the OTP again and sets the new password.
     */
    #[BodyParameter('email', 'Email address or phone number used when requesting OTP.', required: true, type: 'string', example: 'john@example.com')]
    #[BodyParameter('otp', 'The 6-digit OTP code.', required: true, type: 'string', example: '482931')]
    #[BodyParameter('password', 'The new password (min 8 characters).', required: true, type: 'string', example: 'newpassword123')]
    #[BodyParameter('password_confirmation', 'Password confirmation (must match password).', required: true, type: 'string', example: 'newpassword123')]
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'otp' => ['required', 'string', 'size:6'],
            // Bounded so an oversized value cannot burn CPU in the hasher on this
            // unauthenticated endpoint.
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
            'password_confirmation' => ['required', 'string', 'max:255'],
        ]);

        $user = $this->userFor($request->input('email'));

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'No account found.'], 404);
        }

        if (! $this->otpMatches($user, (string) $request->input('otp'))) {
            return response()->json([
                'success' => false,
                'message' => 'The OTP is invalid or has expired. Please request a new one.',
            ], 422);
        }

        $user->update(['password' => Hash::make($request->input('password'))]);

        // Burn the code so it cannot be replayed against the new password.
        Cache::forget($this->otpKey($user->id));

        $this->logActivity('password_reset', "Password reset via OTP for user '{$user->email}'.", [], $user, null, $user);

        if ($user->email) {
            try {
                Mail::send('emails.password-reset.password_reset_confirmation', ['user' => $user], function ($message) use ($user) {
                    $message->to($user->email)->subject('Your '.config('app.name').' password was reset');
                });
            } catch (Exception $e) {
                $this->logActivity('reset_confirm_email_failed', "Failed to send confirmation email: {$e->getMessage()}", [], $user, null, $user);
            }
        }

        return response()->json(['success' => true, 'message' => 'Password reset successfully. You can now log in.']);
    }

    /**
     * Resolve the account a credential belongs to.
     *
     * The credential's shape picks the column, so an email is never matched
     * against the phone column (or the reverse): an `orWhere` across both would
     * let a value that happens to sit in the wrong column resolve to an account.
     */
    private function userFor(?string $credential): ?User
    {
        return User::findByCredential($credential);
    }

    /** Constant-time comparison against the cached code. */
    private function otpMatches(User $user, string $submitted): bool
    {
        $cached = Cache::get($this->otpKey($user->id));

        return is_string($cached) && hash_equals($cached, $submitted);
    }

    /** Cache key holding the pending password-reset OTP for a user. */
    private function otpKey(string $userId): string
    {
        return 'pwreset_otp_'.$userId;
    }
}
