<?php

/**
 * Shops (D31): made as a Draft with a location, sent for review by the Owner, decided by the Admin.
 * Each shop is judged on its own: suspending one leaves the vendor's other shops selling.
 */

use App\Enums\VendorMemberRole;
use App\Models\PayoutDestination;
use App\Models\Shop;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\ShopDecided;
use App\Notifications\ShopSubmitted;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    Queue::fake();
});

/** @return array{0: Vendor, 1: User} */
function vendorWithOwner(bool $approved = true): array
{
    $owner = User::factory()->create();
    $factory = Vendor::factory()->ownedBy($owner);
    $vendor = ($approved ? $factory->approved() : $factory)->create();

    return [$vendor, $owner];
}

/** @return array<string, mixed> */
function newShop(array $overrides = []): array
{
    return [
        'name' => 'Home Nest',
        'slug' => 'HomeNest',
        'shop_type' => 'Household & home',
        'description' => 'Basins, buckets and kitchen plastics, wholesale and retail.',
        'phone' => '0772 123 456',
        'location_address' => 'Plot 12, Kikuubo Lane',
        'location_area' => 'Kikuubo',
        ...$overrides,
    ];
}

it('opens a shop as a Draft with its default location', function () {
    [$vendor, $owner] = vendorWithOwner();
    $this->actingAs($owner, 'sanctum');

    $response = $this->postJson("/api/v1/vendors/{$vendor->id}/shops", newShop())->assertCreated()
        ->assertJsonPath('data.status', 'Draft')
        ->assertJsonPath('data.slug', 'homenest')
        ->assertJsonPath('data.address', 'homenest.kheengz.com');

    $shop = Shop::query()->findOrFail($response->json('data.id'));
    expect($shop->locations()->sole())
        ->is_default->toBeTrue()
        ->area->toBe('Kikuubo');
});

it('keeps address names clean, the platform\'s own, and unique', function () {
    [$vendor, $owner] = vendorWithOwner();
    Shop::factory()->create(['slug' => 'homenest']);
    $this->actingAs($owner, 'sanctum');

    foreach (['ab', '-homenest', 'home--nest', 'home nest', 'admin', 'homenest'] as $bad) {
        $this->postJson("/api/v1/vendors/{$vendor->id}/shops", newShop(['slug' => $bad]))
            ->assertUnprocessable()->assertJsonValidationErrors('slug');
    }

    $this->getJson('/api/v1/shops/slug-availability?slug=homenest')->assertOk()
        ->assertJsonPath('available', false)
        ->assertJsonPath('message', 'homenest is taken. Try adding your area, like homenest-ntinda.');
    $this->getJson('/api/v1/shops/slug-availability?slug=home-nest-ntinda')->assertJsonPath('available', true);
});

it('runs a shop from Draft through review to open, telling each side', function () {
    [$vendor, $owner] = vendorWithOwner();
    $admin = User::factory()->create();
    $admin->assignRole('Admin');
    $shop = Shop::factory()->for($vendor)->create();
    $shop->locations()->create(['name' => 'Main shop', 'address' => 'Kikuubo', 'area' => 'Kikuubo', 'is_default' => true]);

    $this->actingAs($owner, 'sanctum');
    // No payout destination yet: it cannot go for review.
    $this->postJson("/api/v1/shops/{$shop->id}/submit")->assertUnprocessable()->assertJsonValidationErrors('payout_destination_id');
    $destination = PayoutDestination::factory()->for($vendor)->awaitingReview()->create();
    $this->putJson("/api/v1/shops/{$shop->id}", ['payout_destination_id' => $destination->id])->assertOk();

    $this->postJson("/api/v1/shops/{$shop->id}/submit")->assertOk()->assertJsonPath('data.status', 'Pending review');
    Notification::assertSentTo($admin, ShopSubmitted::class);

    // The version under review is the version approved: no edits meanwhile.
    $this->putJson("/api/v1/shops/{$shop->id}", ['description' => 'Changed while waiting'])->assertForbidden();

    $this->actingAs($admin, 'sanctum');
    $this->postJson("/api/v1/shops/{$shop->id}/request-changes", ['reason' => 'Add a clear shop description.'])->assertOk()
        ->assertJsonPath('data.status', 'Changes requested');
    Notification::assertSentTo($owner, ShopDecided::class);

    $this->actingAs($owner, 'sanctum');
    $this->putJson("/api/v1/shops/{$shop->id}", ['description' => 'Plastics for every home, delivered by boda.'])->assertOk();
    $this->postJson("/api/v1/shops/{$shop->id}/submit")->assertOk();

    $this->actingAs($admin, 'sanctum');
    // Money only to a checked account: the destination is decided first.
    $this->postJson("/api/v1/shops/{$shop->id}/approve")->assertUnprocessable()->assertJsonValidationErrors('status');
    $this->postJson("/api/v1/payout-destinations/{$destination->id}/approve")->assertOk();
    $this->postJson("/api/v1/shops/{$shop->id}/approve")->assertOk()->assertJsonPath('data.status', 'Active');

    expect($shop->fresh()->isSelling())->toBeTrue()
        ->and($shop->reviews()->pluck('decision')->map->value->all())
        ->toEqualCanonicalizing(['Submitted', 'Changes requested', 'Submitted', 'Approved']);
});

it('opens no shop of a vendor that is not yet approved', function () {
    [$vendor] = vendorWithOwner(approved: false);
    $shop = Shop::factory()->pendingReview()->for($vendor)->create();
    actingAsAdmin();

    $this->postJson("/api/v1/shops/{$shop->id}/approve")->assertUnprocessable()
        ->assertJsonPath('errors.status.0', 'Approve the vendor first: a shop sells only once the person behind it is verified.');
});

it('fixes an approved shop\'s name, address name and type, but not its description', function () {
    [$vendor, $owner] = vendorWithOwner();
    $shop = Shop::factory()->active()->for($vendor)->create(['slug' => 'homenest']);
    $this->actingAs($owner, 'sanctum');

    $this->putJson("/api/v1/shops/{$shop->id}", ['description' => 'Now with kitchen sets.'])->assertOk();
    $this->putJson("/api/v1/shops/{$shop->id}", ['slug' => 'cheap-phones'])->assertUnprocessable();
    $this->putJson("/api/v1/shops/{$shop->id}", ['shop_type' => 'Electronics & phones'])->assertUnprocessable();
});

it('suspends one shop and leaves the vendor\'s other shops selling', function () {
    [$vendor, $owner] = vendorWithOwner();
    [$stall, $other] = Shop::factory()->count(2)->active()->for($vendor)->create();
    actingAsAdmin();

    $this->postJson("/api/v1/shops/{$stall->id}/suspend", ['reason' => 'Commission unpaid for 14 days.'])->assertOk();

    expect($stall->fresh()->isSelling())->toBeFalse()->and($other->fresh()->isSelling())->toBeTrue();

    // Closing is never a way round a suspension.
    $this->actingAs($owner, 'sanctum');
    $this->postJson("/api/v1/shops/{$stall->id}/close")->assertUnprocessable()->assertJsonValidationErrors('status');
    $this->postJson("/api/v1/shops/{$other->id}/close")->assertOk()->assertJsonPath('data.status', 'Closed');
});

it('shows Staff only the shops they were given, and lets only the Owner send one for review', function () {
    [$vendor] = vendorWithOwner();
    [$given, $notGiven] = Shop::factory()->count(2)->for($vendor)->create();
    $staff = User::factory()->create();
    $staff->assignRole('Vendor');
    $vendor->members()->attach($staff->id, ['role' => VendorMemberRole::Staff->value]);
    $staff->staffShops()->attach($given->id);

    $this->actingAs($staff, 'sanctum');
    $this->getJson('/api/v1/shops')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $given->id);
    $this->getJson("/api/v1/shops/{$notGiven->id}")->assertForbidden();
    $this->putJson("/api/v1/shops/{$given->id}", ['description' => 'Updated by staff'])->assertOk();
    $this->postJson("/api/v1/shops/{$given->id}/submit")->assertForbidden();
    $this->postJson("/api/v1/vendors/{$vendor->id}/shops", newShop())->assertForbidden();
});

it('keeps one vendor out of another vendor\'s shops', function () {
    [, $owner] = vendorWithOwner();
    $theirs = Shop::factory()->create();
    $this->actingAs($owner, 'sanctum');

    $this->getJson("/api/v1/shops/{$theirs->id}")->assertForbidden();
    $this->putJson("/api/v1/shops/{$theirs->id}", ['description' => 'x'])->assertForbidden();
    $this->getJson('/api/v1/shops')->assertJsonCount(0, 'data');
});
