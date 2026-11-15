<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * A notice worth an email as well as the bell (money, a new order): nobody should have to be
 * signed in to learn of it. Sent in the Kheengz layout; the link opens the dashboard page.
 */
abstract class EmailedNotification extends KheengzNotification
{
    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return filled($notifiable->email ?? null) ? ['database', 'broadcast', 'mail'] : ['database', 'broadcast'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $link = $this->link();

        return (new MailMessage)
            ->subject($this->title())
            ->view('emails.notifications.user-notification', [
                'user' => $notifiable,
                'notificationTitle' => $this->title(),
                'notificationBody' => $this->body(),
                'notificationLink' => $link ? rtrim((string) config('app.frontend_url'), '/').$link : null,
            ]);
    }
}
