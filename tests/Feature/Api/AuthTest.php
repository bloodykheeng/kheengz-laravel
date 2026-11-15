<?php

/**
 * Sign in by email, phone or username; an unconfirmed email is confirmed with a 6-digit code.
 * Vendor self sign-up arrives with the vendors module (Phase 1) and brings its own tests.
 */

use App\Models\Activity;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

it('signs in by email and by phone, however the phone is typed', function () {
    $user = User::factory()->create([
        'email' => 'fahim@example.com',
        'phone' => '256701234567',
        'password' => Hash::make('secret123'),
        'status' => 'Active',
    ]);

    foreach (['fahim@example.com', '256701234567', '+256 701 234 567', '0701 234 567'] as $credential) {
        $this->postJson('/api/v1/auth/login', ['email' => $credential, 'password' => 'secret123'])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonStructure(['token']);
    }
});

it('refuses a wrong password with the same message as an unknown account', function () {
    User::factory()->create(['email' => 'fahim@example.com', 'password' => Hash::make('secret123')]);

    $wrong = $this->postJson('/api/v1/auth/login', ['email' => 'fahim@example.com', 'password' => 'nope'])->assertUnprocessable();
    $unknown = $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'nope'])->assertUnprocessable();

    expect($wrong->json('errors.email.0'))->toBe($unknown->json('errors.email.0'));
});

it('refuses an inactive account', function () {
    User::factory()->create(['email' => 'off@example.com', 'password' => Hash::make('secret123'), 'status' => 'Inactive']);

    $this->postJson('/api/v1/auth/login', ['email' => 'off@example.com', 'password' => 'secret123'])->assertForbidden();
});

it('asks an unconfirmed email for its code, and the code works once', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $user = User::factory()->unverified()->create(['email' => 'staff@example.com', 'password' => Hash::make('Kampala2026'), 'status' => 'Active']);
    $user->assignRole('Staff');

    $this->postJson('/api/v1/auth/login', ['email' => 'staff@example.com', 'password' => 'Kampala2026'])
        ->assertForbidden()->assertJsonPath('code', 'email_unverified');

    $this->postJson('/api/v1/auth/email/verify', ['email' => 'staff@example.com', 'code' => '000000'])
        ->assertUnprocessable()->assertJsonValidationErrors('code');

    $code = Cache::get("email-verification:{$user->id}");
    $this->postJson('/api/v1/auth/email/verify', ['email' => 'staff@example.com', 'code' => $code])
        ->assertOk()
        ->assertJsonPath('user.role', 'Staff')
        ->assertJsonStructure(['token']);

    expect($user->fresh()->email_verified_at)->not->toBeNull();
    $this->postJson('/api/v1/auth/email/verify', ['email' => 'staff@example.com', 'code' => $code])->assertUnprocessable();
    $this->postJson('/api/v1/auth/login', ['email' => 'staff@example.com', 'password' => 'Kampala2026'])->assertOk();
});

it('sends a new code on request without saying whether an account exists', function () {
    User::factory()->unverified()->create(['email' => 'waiting@example.com']);

    $known = $this->postJson('/api/v1/auth/email/resend', ['email' => 'waiting@example.com'])->assertOk();
    $unknown = $this->postJson('/api/v1/auth/email/resend', ['email' => 'nobody@example.com'])->assertOk();

    expect($known->json('message'))->toBe($unknown->json('message'));
});

it('records each sign-in in the audit trail with where it came from', function () {
    User::factory()->create(['email' => 'fahim@example.com', 'password' => Hash::make('secret123')]);

    $this->withServerVariables(['REMOTE_ADDR' => '41.210.145.10'])
        ->postJson('/api/v1/auth/login', ['email' => 'fahim@example.com', 'password' => 'secret123'], ['User-Agent' => 'Mozilla/5.0 (Linux; Android 14; Pixel 8) Chrome/126.0 Mobile Safari/537.36'])
        ->assertOk();

    $entry = Activity::query()->where('event', 'user_logged_in')->sole();

    expect($entry->properties['ip'])->toBe('41.210.145.10')
        ->and($entry->properties)->toHaveKeys(['country', 'region', 'city', 'device', 'platform', 'browser'])
        ->and($entry->properties['platform'])->toBe('AndroidOS');
});
