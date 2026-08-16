<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Application identity
    |--------------------------------------------------------------------------
    */
    'name' => env('APP_NAME', 'EruoFood AI'),

    // Custom key surfaced by the Platform health endpoint. Bump on release.
    'version' => env('APP_VERSION', '0.1.0'),

    'env' => env('APP_ENV', 'production'),

    'debug' => (bool) env('APP_DEBUG', false),

    'url' => env('APP_URL', 'http://localhost'),

    /*
    | UTC is authoritative and is not a preference.
    |
    | This drives PHP's default timezone, so it decides what every timestamp
    | written by the application *means*. It was 'Africa/Lagos', which stored
    | local wall-clock in 167 timezone-naive columns while PostgreSQL itself ran
    | in UTC — the two disagreed by an hour, and any second deployment region
    | would have disagreed by more.
    |
    | A user's or merchant's timezone is a display and scheduling concern,
    | carried per record as an IANA identifier and applied at the edge. It is
    | never the storage format. See docs/TIMEZONE_ARCHITECTURE.md.
    */
    'timezone' => env('APP_TIMEZONE', 'UTC'),

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    */
    'key' => env('APP_KEY'),

    'cipher' => 'AES-256-CBC',

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode
    |--------------------------------------------------------------------------
    */
    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],
];
