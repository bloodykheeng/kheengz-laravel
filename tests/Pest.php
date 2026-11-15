<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
| Every Feature test runs on a fresh database (RefreshDatabase) through Laravel's TestCase.
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * A signed-in Admin, holding every permission.
 *
 * The real `RolesAndPermissionsSeeder` runs rather than a hand-written list, so a permission
 * renamed there without the matching `can:` on the route shows up here as a 403 instead of
 * passing against a stale fixture. Sanctum's `actingAs` is used rather than a real token:
 * these tests are about what the endpoints return, not how the token is minted.
 */
function actingAsAdmin(): User
{
    return actingAsRole('Admin');
}

/**
 * A signed-in user holding one role (Admin, Staff or Vendor), with the real seeder behind it.
 */
function actingAsRole(string $role): User
{
    // Through the test helper, not `new ...->run()`: the seeder reports progress on
    // `$this->command`, which only Artisan sets. Idempotent, so calling it again costs nothing.
    test()->seed(RolesAndPermissionsSeeder::class);

    $user = User::factory()->create();
    $user->assignRole($role);

    test()->actingAs($user, 'sanctum');

    return $user;
}
