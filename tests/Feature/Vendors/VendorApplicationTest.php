<?php

/**
 * "Sell on Kheengz" (D35): a new account with the Vendor role and a Pending vendor it owns.
 */

use App\Enums\VendorMemberRole;
use App\Enums\VendorStatus;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

/** @return array<string, mixed> */
function application(array $overrides = []): array
{
    return [
        'name' => 'Fahim Kato',
        'email' => 'fahim@example.com',
        'phone' => '0772 123 456',
        'password' => 'HomeNest2026',
        'password_confirmation' => 'HomeNest2026',
        'vendor_name' => 'Home Nest',
        'kind' => 'Individual',
        // A NIN typed as people type it: small letters and spaces.
        'national_id_number' => 'cm 9012 3456 78ab',
        'address' => 'Kikuubo, Kampala',
        'accept_terms' => true,
        ...$overrides,
    ];
}

it('makes the account and a Pending vendor it owns, and sends the email code', function () {
    $this->postJson('/api/v1/vendor-applications', application())
        ->assertCreated()
        ->assertJsonPath('data.verification_required', true);

    $user = User::query()->where('email', 'fahim@example.com')->sole();
    $vendor = Vendor::query()->sole();

    expect($user->hasRole('Vendor'))->toBeTrue()
        ->and($user->phone)->toBe('256772123456')
        ->and($user->email_verified_at)->toBeNull()
        ->and($vendor->status)->toBe(VendorStatus::Pending)
        ->and($vendor->national_id_number)->toBe('CM9012345678AB')
        ->and($vendor->roleOf($user))->toBe(VendorMemberRole::Owner)
        ->and(Cache::get("email-verification:{$user->id}"))->not->toBeNull();
});

it('refuses a number that is not a NIN', function () {
    foreach (['12345', 'CX9012345678AB', 'CM9012345678ABC'] as $notANin) {
        $this->postJson('/api/v1/vendor-applications', application(['national_id_number' => $notANin]))
            ->assertUnprocessable()->assertJsonValidationErrors('national_id_number');
    }
});

it('asks a company for its registration number and keeps none for an individual', function () {
    $this->postJson('/api/v1/vendor-applications', application(['kind' => 'Company']))
        ->assertUnprocessable()->assertJsonValidationErrors('registration_number');

    $this->postJson('/api/v1/vendor-applications', application(['kind' => 'Individual', 'registration_number' => '80020001234567']))
        ->assertCreated();
    expect(Vendor::query()->value('registration_number'))->toBeNull();
});

it('sends someone already registered to sign in, and keeps one NIN to one vendor', function () {
    User::factory()->create(['email' => 'fahim@example.com']);
    $this->postJson('/api/v1/vendor-applications', application())
        ->assertUnprocessable()->assertJsonPath('errors.email.0', 'An account with this email already exists. Sign in instead.');

    Vendor::factory()->create(['national_id_number' => 'CM9012345678AB']);
    $this->postJson('/api/v1/vendor-applications', application(['email' => 'other@example.com', 'phone' => '0701234567']))
        ->assertUnprocessable()->assertJsonValidationErrors('national_id_number');
});

it('needs the seller terms accepted', function () {
    $this->postJson('/api/v1/vendor-applications', application(['accept_terms' => false]))
        ->assertUnprocessable()->assertJsonValidationErrors('accept_terms');
});
