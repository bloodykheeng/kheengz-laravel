<?php

/**
 * Numbers people read (docs/reference-numbers.md): once handed out, a number means one record
 * forever. Proven here on `users.username` until orders exist; the orders module repeats it on
 * order numbers. From Alpha's SerialNumbersTest (NICE found the bug, 5 Nov 2026).
 */

use App\Models\User;
use App\Support\SerialCounter;
use Carbon\CarbonImmutable;

it('never hands a deleted record’s number to the next one', function () {
    $first = User::factory()->create(['username' => SerialCounter::take(User::query(), 'username', 'KZ112026')]);
    $newest = User::factory()->create(['username' => SerialCounter::take(User::query(), 'username', 'KZ112026')]);

    expect($first->username)->toBe('KZ1120261')->and($newest->username)->toBe('KZ1120262');

    // "Highest still in the table + 1" would give KZ1120262 again now.
    $newest->delete();
    $next = SerialCounter::take(User::query(), 'username', 'KZ112026');

    expect($next)->toBe('KZ1120263');
});

it('keeps each stem’s counter apart and can zero-pad', function () {
    expect(SerialCounter::take(User::query(), 'username', 'A-', 4))->toBe('A-0001')
        ->and(SerialCounter::take(User::query(), 'username', 'B-', 4))->toBe('B-0001')
        ->and(SerialCounter::take(User::query(), 'username', 'A-', 4))->toBe('A-0002');
});

it('dates a number by the Kampala month, not the server’s UTC one', function () {
    // 22:00 UTC on 31 October is 01:00 on 1 November in Kampala.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-31 22:00:00', 'UTC'));

    expect(SerialCounter::monthStem())->toBe('112026');

    CarbonImmutable::setTestNow();
});
