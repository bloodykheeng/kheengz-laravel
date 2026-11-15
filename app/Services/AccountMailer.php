<?php

namespace App\Services;

use App\Models\User;
use App\Traits\LoggableTrait;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The account emails: a new account's sign-in details and an edited account's notice. A mail server that refuses is logged, never a failed request: the
 * account exists either way and the password can be reset.
 */
class AccountMailer
{
    use LoggableTrait;

    public function welcome(User $user, string $plainPassword): void
    {
        $this->send($user, 'emails.users.user-account-created', [
            'user' => $user,
            'plainPassword' => $plainPassword,
        ], 'Your '.config('app.name').' account is ready');
    }

    public function updated(User $user, bool $passwordChanged): void
    {
        $this->send($user, 'emails.users.user-account-updated', [
            'user' => $user,
            'password_changed' => $passwordChanged,
        ], 'Your '.config('app.name').' account was updated');
    }

    /** @param  array<string, mixed>  $data */
    private function send(User $user, string $view, array $data, string $subject): void
    {
        if (blank($user->email)) {
            return;
        }

        try {
            Mail::send($view, $data, fn ($message) => $message->to($user->email)->subject($subject));
        } catch (Throwable $e) {
            $this->logActivity('email_sending_failed', "Could not email '{$user->email}': {$e->getMessage()}", [], $user);
        }
    }
}
