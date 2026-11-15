<?php

/**
 * The bell: everyone reads, counts, marks and removes only their own notices. Real notices
 * (a new order, a payout) arrive with their modules; this one stands in for them.
 */

use App\Models\User;
use App\Notifications\KheengzNotification;

/**
 * A minimal notice in the shape every Kheengz notice has. A named class: queued notices are
 * serialised, and PHP cannot serialise an anonymous one.
 */
final class TestOrderNotice extends KheengzNotification
{
    public function __construct(private string $text) {}

    protected function kind(): string
    {
        return 'order';
    }

    protected function title(): string
    {
        return $this->text;
    }

    protected function body(): string
    {
        return 'Two basins, UGX 10,000.';
    }

    protected function link(): ?string
    {
        return '/dashboard/orders';
    }
}

it('lists, counts and marks read only the caller’s own notices', function () {
    $owner = User::factory()->create();
    $owner->notify(new TestOrderNotice('New order HN-1'));
    $other = User::factory()->create();
    $other->notify(new TestOrderNotice('Someone else’s order'));

    $this->actingAs($owner, 'sanctum');

    $response = $this->getJson('/api/v1/notifications')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('unread_count', 1)
        ->assertJsonPath('data.0.kind', 'order')
        ->assertJsonPath('data.0.title', 'New order HN-1')
        ->assertJsonPath('data.0.link', '/dashboard/orders');

    $this->postJson('/api/v1/notifications/'.$response->json('data.0.id').'/read')->assertOk();
    $this->getJson('/api/v1/notifications/unread-count')->assertJsonPath('unread_count', 0);

    // Someone else's notice is never touched (the answer is the same as for one already read).
    $foreign = $other->notifications()->first()->id;
    $this->postJson("/api/v1/notifications/{$foreign}/read")->assertOk();
    expect($other->unreadNotifications()->count())->toBe(1);
});

it('removes a notice once, takes a second press quietly, and never touches anyone else’s', function () {
    $owner = User::factory()->create();
    $owner->notify(new TestOrderNotice('New order HN-1'));
    $other = User::factory()->create();
    $other->notify(new TestOrderNotice('Someone else’s order'));
    $this->actingAs($owner, 'sanctum');

    $id = $owner->notifications()->first()->id;
    $this->deleteJson("/api/v1/notifications/{$id}")->assertOk();
    $this->deleteJson("/api/v1/notifications/{$id}")->assertOk();
    expect($owner->notifications()->count())->toBe(0);

    $this->deleteJson('/api/v1/notifications/'.$other->notifications()->first()->id)->assertOk();
    expect($other->notifications()->count())->toBe(1);
});
