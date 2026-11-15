<?php

/**
 * A vendor's people (Owner, Staff with chosen shops), a shop's locations, and the platform settings.
 */

use App\Enums\VendorMemberRole;
use App\Models\Shop;
use App\Models\User;
use App\Models\Vendor;
use App\Services\AccountMailer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Queue::fake();
});

it('adds a new person as Staff of chosen shops, with an account and emailed sign-in details', function () {
    $this->mock(AccountMailer::class)->shouldReceive('welcome')->once()
        ->withArgs(fn (User $user, string $password) => $user->email === 'aisha@example.com' && strlen($password) === 12);
    $owner = User::factory()->create();
    $vendor = Vendor::factory()->approved()->ownedBy($owner)->create();
    $shop = Shop::factory()->for($vendor)->create();
    $this->actingAs($owner, 'sanctum');

    $this->postJson("/api/v1/vendors/{$vendor->id}/members", [
        'name' => 'Aisha Nankya', 'email' => 'aisha@example.com', 'phone' => '0701234567',
        'role' => 'Staff', 'shop_ids' => [$shop->id],
    ])->assertCreated()
        ->assertJsonPath('data.role', 'Staff')
        ->assertJsonPath('data.shop_ids', [$shop->id]);

    $aisha = User::query()->where('email', 'aisha@example.com')->sole();
    expect($aisha->hasRole('Vendor'))->toBeTrue()->and($vendor->roleOf($aisha))->toBe(VendorMemberRole::Staff);
});

it('refuses a shop of another vendor, and Staff with no shops', function () {
    $owner = User::factory()->create();
    $vendor = Vendor::factory()->ownedBy($owner)->create();
    $foreign = Shop::factory()->create();
    $this->actingAs($owner, 'sanctum');

    $this->postJson("/api/v1/vendors/{$vendor->id}/members", ['name' => 'Ben', 'email' => 'ben@example.com', 'role' => 'Staff', 'shop_ids' => [$foreign->id]])
        ->assertUnprocessable()->assertJsonValidationErrors('shop_ids.0');
    $this->postJson("/api/v1/vendors/{$vendor->id}/members", ['name' => 'Ben', 'email' => 'ben@example.com', 'role' => 'Staff'])
        ->assertUnprocessable()->assertJsonValidationErrors('shop_ids');
});

it('never leaves a vendor without an Owner', function () {
    $owner = User::factory()->create();
    $vendor = Vendor::factory()->ownedBy($owner)->create();
    $this->actingAs($owner, 'sanctum');

    $this->deleteJson("/api/v1/vendors/{$vendor->id}/members/{$owner->id}")->assertUnprocessable();
    $this->putJson("/api/v1/vendors/{$vendor->id}/members/{$owner->id}", ['role' => 'Staff', 'shop_ids' => []])->assertUnprocessable();
});

it('keeps one default location, and never removes it', function () {
    $owner = User::factory()->create();
    $vendor = Vendor::factory()->ownedBy($owner)->create();
    $shop = Shop::factory()->for($vendor)->create();
    $main = $shop->locations()->create(['name' => 'Main shop', 'address' => 'Kikuubo', 'area' => 'Kikuubo', 'is_default' => true]);
    $this->actingAs($owner, 'sanctum');

    $store = $this->postJson("/api/v1/shops/{$shop->id}/locations", ['name' => 'Store room', 'address' => 'Nakawa', 'area' => 'Nakawa', 'is_default' => true])
        ->assertCreated()->json('data.id');

    expect($main->fresh()->is_default)->toBeFalse();
    $this->deleteJson("/api/v1/shops/{$shop->id}/locations/{$store}")->assertUnprocessable();
    $this->deleteJson("/api/v1/shops/{$shop->id}/locations/{$main->id}")->assertOk();
});

it('lets the Admin read and set the platform\'s money rules, and nobody else', function () {
    actingAsAdmin();
    $this->getJson('/api/v1/platform-settings')->assertOk()
        ->assertJsonPath('data.commission_bps', 300)
        ->assertJsonPath('data.debt_limit_amount', 50000)
        ->assertJsonPath('data.debt_limit_days', 14);

    $this->putJson('/api/v1/platform-settings', ['commission_bps' => 250, 'card_enabled' => true])->assertOk()
        ->assertJsonPath('data.commission_bps', 250);
    $this->putJson('/api/v1/platform-settings', ['commission_bps' => 9000])->assertUnprocessable();

    actingAsRole('Vendor');
    $this->getJson('/api/v1/platform-settings')->assertForbidden();
});
