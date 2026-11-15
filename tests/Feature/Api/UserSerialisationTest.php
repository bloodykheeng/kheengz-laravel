<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Rendering a user must never reach for a relation it has not got.
 *
 * `User::$appends` carries `role`, and `getRoleAttribute()` reads the Spatie
 * `roles` relation, so EVERY serialisation of a user touches it. Under
 * `preventLazyLoading()` that is an exception rather than an extra query, and
 * it only shows where users arrive as a COLLECTION, which is what Laravel 13
 * guards. See docs/n-plus-one-guard.md.
 *
 * The rule was documented and drifted twice all the same: first on the
 * notification recipient list, then on the bell's own feed, which eager-loaded
 * `createdBy` for the author and threw the moment a notification had one. Both
 * were fixed one call site at a time, and there are thirty-odd call sites. It
 * is `User::$with` that holds it now, and these are the cases that prove it.
 */
it('renders a page of users without loading their roles by hand', function () {
    actingAsRole('Admin');

    // A collection is the only shape Laravel 13 guards, so a single record
    // would pass here whatever the model did.
    User::factory()->count(3)->create();

    $rows = test()->getJson('/api/v1/users')->assertOk()->json('data');

    expect($rows)->not->toBeEmpty()
        ->and($rows[0])->toHaveKey('role');
});

it('loads roles with any user the database returns', function () {
    actingAsRole('Admin');
    User::factory()->count(3)->create();

    // Straight off the model, with no `with()` anywhere: this is the guarantee
    // the thirty call sites are relying on, so it is asserted rather than
    // inferred from the two endpoints above.
    User::query()->get()->each(
        fn (User $user) => expect($user->relationLoaded('roles'))->toBeTrue()
    );
});
