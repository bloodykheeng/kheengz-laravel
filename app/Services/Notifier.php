<?php

namespace App\Services;

use App\Jobs\SendSmsJob;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as Notifications;

/**
 * Who hears about what. The notifications themselves are queued, so these
 * calls cost one query for the recipients and return at once.
 */
class Notifier
{
    /** Every active Admin, except the person who caused it. */
    public function admins(Notification $notification, ?User $except = null): void
    {
        $recipients = User::role('Admin')
            ->where('status', 'Active')
            ->when($except, fn ($q) => $q->whereKeyNot($except->getKey()))
            ->get();

        Notifications::send($recipients, $notification);
    }

    /**
     * The same news by SMS to every active Admin with a Ugandan mobile, for what needs someone
     * at once (a payout that failed, a buyer charged twice). Queued, one job per Admin.
     */
    public function smsAdmins(string $message, ?User $except = null): void
    {
        User::role('Admin')
            ->where('status', 'Active')
            ->when($except, fn ($q) => $q->whereKeyNot($except->getKey()))
            ->whereNotNull('phone')
            ->pluck('phone')
            ->filter(fn ($phone) => Phone::isUgandan($phone))
            ->each(fn ($phone) => SendSmsJob::dispatch($phone, $message));
    }
}
