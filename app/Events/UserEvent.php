<?php

namespace App\Events;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * A broadcast about a row that no longer exists has nothing to say.
     *
     * `SerializesModels` queues the KEY and re-fetches when the worker runs, so
     * a `created` or `updated` event whose row is deleted in the seconds before
     * the job is picked up throws `ModelNotFoundException` and lands in
     * `failed_jobs`. Nothing is wrong when that happens: the row is gone, the
     * screens will refetch without it, and there is no message left to send.
     *
     * Post a comment and delete it two seconds later and this is the ordinary
     * path, not an edge case. Laravel's own switch for it, rather than a
     * try/catch around every listener.
     */
    public $deleteWhenMissingModels = true;

    /**
     * @param  string  $action  One of: created, updated, deleted
     * @param  User|null  $user  The model (null when action is deleted)
     * @param  string|null  $userId  The ID (used only when action is deleted)
     */
    public function __construct(
        public readonly string $action,
        public readonly ?User $user = null,
        public readonly ?string $userId = null,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel('users')];
    }

    public function broadcastAs(): string
    {
        return "user.{$this->action}";
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        if ($this->action === 'deleted') {
            return ['id' => $this->userId];
        }

        return (new UserResource($this->user))->resolve();
    }
}
