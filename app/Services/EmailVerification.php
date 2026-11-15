<?php

namespace App\Services;

use App\Models\User;
use App\Traits\LoggableTrait;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Proving an email address at sign-up with a 6-digit code (Alpha's onboarding pattern), kept in
 * the cache like the password reset codes (docs/otp-and-short-lived-secrets.md): it expires on its
 * own, a new one replaces the last, and it is compared in constant time.
 */
class EmailVerification
{
    use LoggableTrait;

    public const CODE_TTL_MINUTES = 10;

    /** A sign-up never confirmed is deleted after this many days (users:prune-unconfirmed). */
    public const PRUNE_AFTER_DAYS = 30;

    /**
     * Send a fresh code. False when the mail server refused it. `$by` is the Admin who asked for it
     * from Users; the audit row names them, otherwise the person signing up.
     */
    public function send(User $user, ?User $by = null): bool
    {
        $code = (string) random_int(100000, 999999);
        Cache::put($this->key($user), $code, now()->addMinutes(self::CODE_TTL_MINUTES));

        try {
            Mail::send('emails.users.verify-email', ['user' => $user, 'code' => $code, 'minutes' => self::CODE_TTL_MINUTES], function ($message) use ($user, $code) {
                $message->to($user->email)->subject('Your '.config('app.name')." code is {$code}");
            });
        } catch (Throwable $e) {
            $this->logActivity('verification_email_failed', "Could not send the verification code to '{$user->email}': {$e->getMessage()}", [], $user, null, $by ?? $user);

            return false;
        }

        $this->logActivity('verification_code_sent', "A verification code was sent to '{$user->email}'".($by ? " at {$by->name}'s request." : '.'), [], $user, null, $by ?? $user);

        return true;
    }

    /**
     * Check the code. On success the email is verified and the code is burnt. Returns false for
     * a wrong or expired code.
     */
    public function verify(User $user, string $code): bool
    {
        $cached = Cache::get($this->key($user));
        if (! is_string($cached) || ! hash_equals($cached, $code)) {
            return false;
        }

        Cache::forget($this->key($user));
        $user->forceFill(['email_verified_at' => now()])->save();

        $this->logActivity('email_verified', "{$user->name} verified '{$user->email}'.", [], $user, null, $user);

        return true;
    }

    private function key(User $user): string
    {
        return "email-verification:{$user->id}";
    }
}
