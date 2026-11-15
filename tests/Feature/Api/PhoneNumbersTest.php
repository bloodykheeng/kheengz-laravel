<?php

/**
 * Phones are stored as digits with the country code (no "+"), typed however people like.
 * Contacts may be from any country; Mobile Money and SMS are Ugandan only.
 */

use App\Models\User;
use App\Services\SmsSender;
use App\Support\Phone;

beforeEach(fn () => config(['services.sms.driver' => 'log']));

it('stores every number as digits with its country code', function () {
    expect(Phone::international('0772 123 456'))->toBe('256772123456')
        ->and(Phone::international('+256 772 123456'))->toBe('256772123456')
        ->and(Phone::international('+44 7911 123456'))->toBe('447911123456')
        ->and(Phone::international('0044 7911 123456'))->toBe('447911123456')
        ->and(Phone::international('07911 123456'))->toBeNull()   // a foreign number needs its country code
        ->and(Phone::local('447911123456'))->toBe('+447911123456')
        ->and(Phone::local('256772123456'))->toBe('0772123456');
});

it('texts Ugandan numbers only', function () {
    $sms = app(SmsSender::class);

    expect($sms->send('256772123456', 'Hello')['ok'])->toBeTrue()
        ->and($sms->send('447911123456', 'Hello')['ok'])->toBeFalse();
});

it('finds an account by phone however the number is typed', function () {
    User::factory()->create(['phone' => '256772123456', 'password' => 'secret-pass-1', 'status' => 'Active']);

    expect(User::findByCredential('0772 123 456')?->phone)->toBe('256772123456')
        ->and(User::findByCredential('+256772123456')?->phone)->toBe('256772123456');
});

it('saves an account phone in the one stored shape', function () {
    actingAsAdmin();

    $this->postJson('/api/v1/users', ['name' => 'Kato Brian', 'email' => 'kato@example.com', 'phone' => '0772 123 456', 'role' => 'Staff', 'password' => 'Kampala2026'])
        ->assertCreated();

    expect(User::query()->firstWhere('email', 'kato@example.com')->phone)->toBe('256772123456');
});
