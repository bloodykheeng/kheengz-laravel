<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Throwable;

/**
 * Something on a dashboard screen changed for these people: an order placed or moved on, a
 * payout sent, stock changed, a vendor approved.
 * Alpha's ApprovalDecided pattern: a signal, not the record. Each person's own private channel
 * gets the resource name and ids; their open screens refetch what shows that resource
 * (Next `lib/liveRecords.ts`). Sent after commit, so the refetch sees the change.
 *
 * Broadcast NOW, not queued: it is a few bytes, and behind the queue it waited for every email
 * and notification job ahead of it, so the other laptop showed "Waiting for an answer" long after
 * the answer. With Reverb down the send fails quietly (reported) and the action still succeeds;
 * screens also refetch on focus. The web side: kheengz-nextjs lib/liveRecords.ts.
 */
class RecordsChanged implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    // One constant per resource a screen refetches, added with its module (ORDERS, PAYOUTS...).
    public const USERS = 'users';

    /** @param  list<string>  $userIds */
    public function __construct(
        public readonly string $resource,
        public readonly array $userIds,
        public readonly ?string $id = null,
        public readonly ?string $vendorId = null,
    ) {}

    /**
     * Tell these people (users or ids), and every active Admin when `$admins`, that `$resource`
     * changed. Does nothing when nobody is left to tell.
     *
     * @param  iterable<User|string|null>  $users
     */
    public static function tell(string $resource, iterable $users, bool $admins = false, ?string $id = null, ?string $vendorId = null): void
    {
        $ids = collect($users)
            ->map(fn ($u) => $u instanceof User ? $u->id : $u)
            ->filter()
            ->when($admins, fn ($c) => $c->merge(User::role('Admin')->where('status', 'Active')->pluck('id')))
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return;
        }

        try {
            // Not to the screen that made the change (its X-Socket-ID): it refreshed itself already.
            broadcast(new self($resource, $ids, $id, $vendorId))->toOthers();
        } catch (Throwable $e) {
            // A missed signal makes a screen late, never wrong: never fail the action for it.
            report($e);
        }
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return array_map(fn (string $id) => new PrivateChannel("App.Models.User.{$id}"), $this->userIds);
    }

    public function broadcastAs(): string
    {
        return 'records.changed';
    }

    /** @return array<string, string|null> */
    public function broadcastWith(): array
    {
        return ['resource' => $this->resource, 'id' => $this->id, 'vendor_id' => $this->vendorId];
    }
}
