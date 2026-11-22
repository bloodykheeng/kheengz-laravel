<?php

use App\Enums\AttributeInputType;
use App\Models\Category;
use App\Models\ProductAttribute;
use App\Models\Shop;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
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

/**
 * A signed-in Owner of an Approved vendor, with one shop (a Draft unless `$shop` says otherwise).
 *
 * @param  array<string, mixed>  $shop
 * @return array{0: Shop, 1: User}
 */
function signedInShopOwner(array $shop = []): array
{
    test()->seed(RolesAndPermissionsSeeder::class);

    $owner = User::factory()->create(['status' => 'Active']);
    $vendor = Vendor::factory()->approved()->ownedBy($owner)->create();
    $model = Shop::factory()->for($vendor)->create($shop);

    test()->actingAs($owner, 'sanctum');

    return [$model, $owner];
}

/**
 * A small slice of the catalogue: Household (Colour) > Kitchen > Basins (Capacity, required), and the
 * units piece (whole) and kg (decimals).
 *
 * @return array{household: Category, basins: Category, colour: ProductAttribute, capacity: ProductAttribute, piece: Unit, kg: Unit}
 */
function catalogueFixture(): array
{
    $colour = ProductAttribute::query()->create(['name' => 'Colour', 'input_type' => AttributeInputType::List]);
    foreach (['Red', 'Blue', 'Green'] as $i => $value) {
        $colour->options()->create(['value' => $value, 'position' => $i]);
    }
    $capacity = ProductAttribute::query()->create(['name' => 'Capacity', 'input_type' => AttributeInputType::Number, 'unit' => 'L']);

    $household = Category::factory()->create(['name' => 'Household', 'slug' => 'household']);
    $household->productAttributes()->attach($colour->id, ['position' => 0, 'is_required' => false]);
    $kitchen = Category::factory()->under($household)->create(['name' => 'Kitchen', 'slug' => 'kitchen']);
    $basins = Category::factory()->under($kitchen)->create(['name' => 'Basins', 'slug' => 'basins']);
    $basins->productAttributes()->attach($capacity->id, ['position' => 0, 'is_required' => true]);

    return [
        'household' => $household,
        'basins' => $basins,
        'colour' => $colour,
        'capacity' => $capacity,
        'piece' => Unit::query()->create(['name' => 'piece', 'plural' => 'pieces', 'allows_decimals' => false]),
        'kg' => Unit::query()->create(['name' => 'kg', 'plural' => 'kg', 'allows_decimals' => true]),
    ];
}
