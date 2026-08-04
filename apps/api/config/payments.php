<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Payments, Wallet & Financial Services configuration
|------------------------------------------------------------------------------
| Tunables for the Payments bounded context: default currency, the default
| provider + fallback chain, per-provider credentials, the commission/fee model,
| settlement and escrow policy, and wallet limits. The "mock" provider is
| deterministic and needs no credentials — when APP_ENV=testing we force it so
| the suite runs fully offline (see `default`). Money is in integer minor units.
*/

return [
    'currency' => env('PAYMENTS_CURRENCY', 'NGN'),

    /*
    |--------------------------------------------------------------------------
    | Provider selection. The factory resolves `default` first; a caller may
    | name a specific provider. `fallbacks` are tried in order when the default
    | provider is unavailable. In testing we force the offline mock provider.
    |--------------------------------------------------------------------------
    */
    'default' => env('PAYMENTS_PROVIDER', env('APP_ENV') === 'testing' ? 'mock' : 'paystack'),

    /** @var list<string> */
    'fallbacks' => array_values(array_filter(explode(',', (string) env('PAYMENTS_FALLBACKS', 'flutterwave,moniepoint')))),

    /*
    |--------------------------------------------------------------------------
    | Per-provider adapters. `enabled` gates a provider on/off; Stripe & PayPal
    | ship as architecture-ready adapters (disabled by default). Secrets come
    | from the environment and are never committed.
    |--------------------------------------------------------------------------
    */
    'providers' => [
        'paystack' => [
            'enabled' => (bool) env('PAYSTACK_ENABLED', true),
            'secret_key' => env('PAYSTACK_SECRET_KEY', ''),
            'public_key' => env('PAYSTACK_PUBLIC_KEY', ''),
            'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
            'webhook_secret' => env('PAYSTACK_WEBHOOK_SECRET', ''),
        ],
        'flutterwave' => [
            'enabled' => (bool) env('FLUTTERWAVE_ENABLED', true),
            'secret_key' => env('FLUTTERWAVE_SECRET_KEY', ''),
            'public_key' => env('FLUTTERWAVE_PUBLIC_KEY', ''),
            'base_url' => env('FLUTTERWAVE_BASE_URL', 'https://api.flutterwave.com/v3'),
            'webhook_secret' => env('FLUTTERWAVE_WEBHOOK_SECRET', ''),
        ],
        'moniepoint' => [
            'enabled' => (bool) env('MONIEPOINT_ENABLED', true),
            'secret_key' => env('MONIEPOINT_SECRET_KEY', ''),
            'base_url' => env('MONIEPOINT_BASE_URL', 'https://api.moniepoint.com'),
            'webhook_secret' => env('MONIEPOINT_WEBHOOK_SECRET', ''),
        ],
        'stripe' => [
            'enabled' => (bool) env('STRIPE_ENABLED', false), // architecture-ready
            'secret_key' => env('STRIPE_SECRET_KEY', ''),
            'base_url' => env('STRIPE_BASE_URL', 'https://api.stripe.com/v1'),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET', ''),
        ],
        'paypal' => [
            'enabled' => (bool) env('PAYPAL_ENABLED', false), // architecture-ready
            'client_id' => env('PAYPAL_CLIENT_ID', ''),
            'secret' => env('PAYPAL_SECRET', ''),
            'base_url' => env('PAYPAL_BASE_URL', 'https://api-m.paypal.com'),
            'webhook_secret' => env('PAYPAL_WEBHOOK_SECRET', ''),
        ],
        'mock' => [
            'enabled' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Commission & platform fees. The platform takes a percentage commission
    | (basis points) plus an optional flat fee on each settled payment; payment
    | processing fees can be passed through. Vendor payout = gross − commission
    | − fees.
    |--------------------------------------------------------------------------
    */
    'commission' => [
        'rate_bps' => (int) env('PAYMENTS_COMMISSION_BPS', 1000), // 10%
        'flat_fee_minor' => (int) env('PAYMENTS_COMMISSION_FLAT', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Escrow & settlement. When escrow is on, a customer payment is held by the
    | platform wallet and only released to the vendor on fulfilment; settlements
    | roll released funds into vendor payouts on the configured cadence.
    |--------------------------------------------------------------------------
    */
    'escrow' => [
        'enabled' => (bool) env('PAYMENTS_ESCROW_ENABLED', true),
    ],
    'settlement' => [
        'cadence' => env('PAYMENTS_SETTLEMENT_CADENCE', 'daily'), // daily|weekly
        'min_payout_minor' => (int) env('PAYMENTS_MIN_PAYOUT', 100000), // ₦1,000
    ],

    /*
    |--------------------------------------------------------------------------
    | Wallet limits & retry policy for provider calls.
    |--------------------------------------------------------------------------
    */
    'wallet' => [
        'max_balance_minor' => (int) env('PAYMENTS_WALLET_MAX', 5000000000), // ₦50m
        'low_balance_minor' => (int) env('PAYMENTS_WALLET_LOW', 50000),      // ₦500 alert
    ],
    'retry' => [
        'max_attempts' => (int) env('PAYMENTS_RETRY_ATTEMPTS', 3),
    ],
];
