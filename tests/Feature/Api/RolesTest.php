<?php

/**
 * The account roles (workspace D26): Admin, Staff, Vendor. Buyers hold none.
 */

use App\Models\User;
use Spatie\Permission\Models\Role;

it('seeds exactly Admin, Staff and Vendor', function () {
    actingAsAdmin();

    expect(Role::query()->orderBy('name')->pluck('name')->all())->toBe(['Admin', 'Staff', 'Vendor']);
});

it('lets an Admin give out every account role', function () {
    actingAsAdmin();

    $this->getJson('/api/v1/roles-permissions/assignable')->assertOk()->assertExactJson(['Admin', 'Staff', 'Vendor']);
});

it('creates an account with one role and refuses a role that does not exist', function () {
    actingAsAdmin();

    $this->postJson('/api/v1/users', ['name' => 'Kato Brian', 'email' => 'kato@example.com', 'role' => 'Staff', 'password' => 'Kampala2026'])
        ->assertCreated()
        ->assertJsonPath('data.role', 'Staff');

    $this->postJson('/api/v1/users', ['name' => 'Old Role', 'email' => 'old@example.com', 'role' => 'Event Manager'])
        ->assertUnprocessable()->assertJsonValidationErrors('role');

    expect(User::query()->firstWhere('email', 'kato@example.com')->getRoleNames()->all())->toBe(['Staff']);
});

it('keeps Staff and Vendors out of accounts, roles, the queue and the audit trail', function (string $role) {
    actingAsRole($role);

    $this->getJson('/api/v1/users')->assertForbidden();
    $this->postJson('/api/v1/users', ['name' => 'X', 'email' => 'x@example.com', 'role' => 'Admin'])->assertForbidden();
    $this->getJson('/api/v1/roles-permissions')->assertForbidden();
    $this->getJson('/api/v1/jobs')->assertForbidden();
    $this->getJson('/api/v1/activity-logs')->assertForbidden();
    $this->getJson('/api/v1/roles-permissions/assignable')->assertOk()->assertExactJson([]);
})->with(['Staff', 'Vendor']);
