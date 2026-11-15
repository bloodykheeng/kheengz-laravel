<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Which browser origins may call the API. The web app lives on its own
    | host, the API on another (api.), so every
    | call is cross-origin and the browser asks first.
    |
    */

    // Broadcasting auth is mounted under api/v1 (bootstrap/app.php), so api/* has it.
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // Comma-separated list of frontend origins (scheme + host, no path), from the
    // environment so each server allows only its own frontend.
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:3000'))
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Sign-in is a bearer token in the Authorization header, not a cookie.
    'supports_credentials' => false,

];
