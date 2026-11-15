<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Every in-app notice: stored (the bell's list) and broadcast on the user's
 * private channel `App.Models.User.{id}` (the live toast and badge).
 *
 * Queued, so an order landing never waits on writing four people's notices, and
 * a stopped Reverb server never fails a payment. The same shape (Alpha's)
 * reaches the bell however it arrives: title, body, icon, link, kind.
 */
abstract class KheengzNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** One word the frontend picks an icon and colour from: order, payout, stock, warning, vendor, shop, account. */
    abstract protected function kind(): string;

    abstract protected function title(): string;

    abstract protected function body(): string;

    /** Where tapping the notice goes, a path on the Next app. */
    abstract protected function link(): ?string;

    /** The shop the notice is about, when it is about one (the bell can filter by shop). */
    protected function vendorId(): ?string
    {
        return null;
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind(),
            'title' => $this->title(),
            'body' => $this->body(),
            'link' => $this->link(),
            'vendor_id' => $this->vendorId(),
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage([...$this->toArray($notifiable), 'created_at' => now()->toISOString()]);
    }

    public function broadcastType(): string
    {
        return 'kheengz.notification';
    }
}
