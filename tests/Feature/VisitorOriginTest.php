<?php

/**
 * Where visitors are (docs/visitor-origin.md, D29): DB-IP City Lite read from our own disk, refreshed
 * monthly. The real file is 120 MB, so these tests fake the download and never fetch it.
 */

use App\Support\DbIpCityLite;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Stevebauman\Location\Facades\Location;

it('looks places up with DB-IP City Lite and nothing that sends the IP elsewhere', function () {
    expect(config('location.driver'))->toBe(DbIpCityLite::class)
        ->and(config('location.fallbacks'))->toBe([])
        ->and(config('location.maxmind.web.enabled'))->toBeFalse()
        ->and(Location::drivers())->toHaveCount(1);
});

it('tries this month’s file, then last month’s, and keeps the current file when neither exists', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-01 00:30:00', 'UTC'));
    config(['location.maxmind.local.path' => $path = storage_path('framework/testing/geoip/dbip-city-lite.mmdb')]);
    File::ensureDirectoryExists(dirname($path));
    File::put($path, 'the database already installed');

    Http::fake(['download.db-ip.com/*' => Http::response('', 404)]);

    expect(fn () => $this->artisan('location:update')->run())
        ->toThrow(RuntimeException::class, 'Could not download DB-IP City Lite');

    Http::assertSentInOrder([
        fn (Request $request) => $request->url() === 'https://download.db-ip.com/free/dbip-city-lite-2026-12.mmdb.gz',
        fn (Request $request) => $request->url() === 'https://download.db-ip.com/free/dbip-city-lite-2026-11.mmdb.gz',
    ]);
    expect(File::get($path))->toBe('the database already installed')
        ->and(File::exists(dirname($path).'/dbip-city-lite.mmdb.gz'))->toBeFalse();

    File::deleteDirectory(dirname($path));
    CarbonImmutable::setTestNow();
});

it('refreshes the database every month', function () {
    $this->artisan('schedule:list')->expectsOutputToContain('location:update')->assertSuccessful();
});
