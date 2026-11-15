<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Bulk SMS (from GiftNest). `driver`:
    //   log      nothing leaves the server; each message is written to the log
    //            and recorded as Sent. For local development and demos.
    //   gateway  the GET-with-query bulk gateway Alpha and SDS use. With
    //            SMS_API_URL blank every message is recorded as Failed with
    //            "SMS gateway is not configured", never lost silently.
    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
        'url' => env('SMS_API_URL'),
        'account_id' => env('SMS_ACCOUNT_ID'),
        'auth_code' => env('SMS_AUTH_CODE'),
        'sender' => env('SMS_SENDER'),
        // Numbers per queued batch job.
        'batch_size' => (int) env('SMS_BATCH_SIZE', 100),
    ],

    // MarzPay (docs: workspace docs/marzpay.md). One base URL for sandbox and live: the account's
    // mode decides. HTTP Basic with the key and secret (their OpenAPI spec). Bank and payout calls
    // need the server's IP whitelisted in the MarzPay dashboard. Read only by the MarzPay client.
    'marzpay' => [
        'base_url' => env('MARZPAY_BASE_URL', 'https://wallet.wearemarz.com/api/v1'),
        'api_key' => env('MARZPAY_API_KEY'),
        'api_secret' => env('MARZPAY_API_SECRET'),
        'timeout' => (int) env('MARZPAY_TIMEOUT', 20),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Firebase Cloud Messaging service account (Firebase > Project settings > Service accounts >
    // Generate new private key). Dev = project kheengz-dev, production = kheengz-6f97b; the same
    // project as the Next app's NEXT_PUBLIC_FIREBASE_*. Read here, never through env() in app code,
    // so `php artisan config:cache` on the server keeps push working (Alpha's pattern).
    'firebase' => [
        'type' => env('FIREBASE_TYPE', 'service_account'),
        'project_id' => env('FIREBASE_PROJECT_ID'),
        'private_key_id' => env('FIREBASE_PRIVATE_KEY_ID'),
        'private_key' => env('FIREBASE_PRIVATE_KEY'),
        'client_email' => env('FIREBASE_CLIENT_EMAIL'),
        'client_id' => env('FIREBASE_CLIENT_ID'),
        'auth_uri' => env('FIREBASE_AUTH_URI', 'https://accounts.google.com/o/oauth2/auth'),
        'token_uri' => env('FIREBASE_TOKEN_URI', 'https://oauth2.googleapis.com/token'),
        'auth_provider_x509_cert_url' => env('FIREBASE_AUTH_PROVIDER_X509_CERT_URL', 'https://www.googleapis.com/oauth2/v1/certs'),
        'client_x509_cert_url' => env('FIREBASE_CLIENT_X509_CERT_URL'),
        'universe_domain' => env('FIREBASE_UNIVERSE_DOMAIN', 'googleapis.com'),
    ],

];
