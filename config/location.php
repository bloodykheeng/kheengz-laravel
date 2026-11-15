<?php

use App\Support\DbIpCityLite;
use Stevebauman\Location\Position;

return [

    /*
    |--------------------------------------------------------------------------
    | Driver
    |--------------------------------------------------------------------------
    |
    | The default driver you would like to use for location retrieval.
    |
    */

    // DB-IP's free City Lite database, read from our own disk: no account, no key, no rate limit,
    // no visitor's IP sent anywhere, commercial use allowed with a credit to DB-IP (CC BY 4.0).
    // Not ip-api.com (free tier is non-commercial, 45 a minute, plain HTTP) nor MaxMind GeoLite
    // (free for internal use only; showing places to vendors needs a USD 10,000 a year licence).
    // `php artisan location:update` downloads it (scheduled monthly in routes/console.php); until
    // the file exists lookups return nothing and records keep a null place.
    'driver' => DbIpCityLite::class,

    /*
    |--------------------------------------------------------------------------
    | Driver Fallbacks
    |--------------------------------------------------------------------------
    |
    | The drivers you want to use to retrieve the user's location
    | if the above selected driver is unavailable.
    |
    | These will be called upon in order (first to last).
    |
    */

    // None: the web services would send our visitors' addresses elsewhere. A miss is a null place.
    'fallbacks' => [],

    /*
    |--------------------------------------------------------------------------
    | Position
    |--------------------------------------------------------------------------
    |
    | Here you may configure the position instance that is created
    | and returned from the above drivers. The instance you
    | create must extend the built-in Position class.
    |
    */

    'position' => Position::class,

    /*
    |--------------------------------------------------------------------------
    | HTTP Client Options
    |--------------------------------------------------------------------------
    |
    | Here you may configure the options used by the underlying
    | Laravel HTTP client. This will be used in drivers that
    | request info via HTTP requests through API services.
    |
    */

    'http' => [
        'timeout' => 3,
        'connect_timeout' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Total Timeout
    |--------------------------------------------------------------------------
    |
    | The seconds a lookup may take across the driver above and all of its
    | fallbacks. Set to null to instead give each driver its own timeout,
    | which makes a failing lookup take them all added up.
    |
    */

    'total_timeout' => null,

    /*
    |--------------------------------------------------------------------------
    | Localhost Testing
    |--------------------------------------------------------------------------
    |
    | If your running your website locally and want to test different
    | IP addresses to see location detection, set 'enabled' to true.
    |
    | The testing IP address is a Google host in the United-States.
    |
    */

    'testing' => [
        'ip' => '66.102.0.0',
        // Off: when on, every local request is placed at a Google address in the United States.
        'enabled' => env('LOCATION_TESTING', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | MaxMind Configuration
    |--------------------------------------------------------------------------
    |
    | If web service is enabled, you must fill in your user ID and license key.
    |
    | If web service is disabled, it will try and retrieve the user's location
    | from the MaxMind database file located in the local path below.
    |
    | The MaxMind database file can be either City (default) or Country (smaller).
    |
    | The web "locales" list also sets the name language for the local database.
    |
    */

    // DbIpCityLite extends the MaxMind driver (same file format), so it reads this block. Only
    // `local.path` and `local.type` matter; no MaxMind account or key is used.
    'maxmind' => [
        'license_key' => null,

        'web' => [
            'enabled' => false,
            'user_id' => env('MAXMIND_USER_ID'),
            'locales' => ['en'],
            'options' => ['host' => 'geoip.maxmind.com'],
        ],

        // Shared storage for several servers: off, each server downloads its own copy.
        'storage' => [
            'disk' => null,
            'path' => 'geoip/dbip-city-lite.mmdb',
            'ttl' => 3600,
        ],

        'local' => [
            'type' => 'city',
            'path' => database_path('geoip/dbip-city-lite.mmdb'),
        ],
    ],

    'ip_api' => [
        'token' => env('IP_API_TOKEN'),
    ],

    'ipinfo' => [
        'token' => env('IPINFO_TOKEN'),
    ],

    'ipdata' => [
        'token' => env('IPDATA_TOKEN'),
    ],

    'ip2locationio' => [
        'token' => env('IP2LOCATIONIO_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Kloudend ~ ipapi.co Configuration
    |--------------------------------------------------------------------------
    |
    | The configuration for the Kloudend driver.
    |
    */

    'kloudend' => [

        'token' => env('KLOUDEND_TOKEN'),

    ],

];
