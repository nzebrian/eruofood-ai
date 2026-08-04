<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Identity & Access module configuration
|------------------------------------------------------------------------------
| All tunables for authentication, tokens, 2FA, and social providers live here
| so the module has a single, documented configuration surface.
*/

return [
    // JWT access-token settings (stateless, short-lived).
    'jwt' => [
        // Fall back to APP_KEY when JWT_SECRET is unset OR empty.
        'secret' => env('JWT_SECRET') ?: env('APP_KEY'),
        'algo' => env('JWT_ALGO', 'HS256'),
        'issuer' => env('JWT_ISSUER', 'eruofood.ai'),
        'audience' => env('JWT_AUDIENCE', 'eruofood.ai'),
        // Access-token lifetime in minutes.
        'ttl' => (int) env('JWT_TTL', 15),
    ],

    // Opaque refresh tokens (rotated, stored hashed) drive sessions.
    'refresh' => [
        // Refresh-token lifetime in days.
        'ttl_days' => (int) env('AUTH_REFRESH_TTL_DAYS', 30),
        // Rotate (issue a new refresh token, revoke the old) on each use.
        'rotate' => true,
    ],

    // Email verification / password reset link lifetime (minutes).
    'tokens' => [
        'email_verification_ttl' => (int) env('AUTH_EMAIL_VERIFY_TTL', 60 * 24),
        'password_reset_ttl' => (int) env('AUTH_PASSWORD_RESET_TTL', 60),
    ],

    // Two-factor authentication (TOTP).
    'two_factor' => [
        'issuer' => env('TWO_FACTOR_ISSUER', 'EruoFood AI'),
        'recovery_code_count' => 8,
        'window' => 1, // accepted TOTP drift (± periods)
    ],

    // Social / external identity providers. `enabled` toggles availability so
    // Apple and phone auth are wired but disabled until configured.
    'providers' => [
        'google' => [
            'enabled' => (bool) env('GOOGLE_AUTH_ENABLED', true),
            'client_id' => env('GOOGLE_CLIENT_ID'),
        ],
        'apple' => [
            'enabled' => (bool) env('APPLE_AUTH_ENABLED', false),
            'client_id' => env('APPLE_CLIENT_ID'),
        ],
        'phone' => [
            'enabled' => (bool) env('PHONE_AUTH_ENABLED', false),
        ],
    ],

    // Password policy (enforced by the Password value object).
    'password' => [
        'min_length' => 8,
    ],
];
