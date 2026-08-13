<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Verification (KYC / KYB) module configuration
|------------------------------------------------------------------------------
| Identity and business verification for every subject the platform onboards:
| customers (progressive), riders (mandatory KYC) and businesses (KYB).
|
| Nothing provider-specific leaks past this file and the matching adapter —
| credentials, endpoints, workflow ids and country routing all live here so the
| same build runs unchanged across dev/stage/prod, and so a second provider can
| be added without touching business logic.
*/

return [
    /*
    |--------------------------------------------------------------------------
    | Enforcement gate  ← READ THIS BEFORE ENABLING
    |--------------------------------------------------------------------------
    | When false, verification is recorded and reviewable but never blocks a
    | rider from being assigned or a merchant from trading. This exists because
    | turning the gate on against an unmigrated population would instantly
    | delist every existing legitimate business and rider.
    |
    | The safe rollout is: deploy with this OFF, backfill and review the current
    | population, confirm the queue is clear, then switch it on deliberately.
    | See docs/M24_KYC_KYB_REPORT.md for the production activation procedure.
    */
    'enforcement' => [
        'enabled' => (bool) env('VERIFICATION_ENFORCEMENT_ENABLED', false),

        // Per-subject overrides, so enforcement can be phased in one population
        // at a time rather than all at once. Each falls back to the master flag.
        'riders' => env('VERIFICATION_ENFORCE_RIDERS'),
        'restaurants' => env('VERIFICATION_ENFORCE_RESTAURANTS'),
        'groceries' => env('VERIFICATION_ENFORCE_GROCERIES'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    | `mock` is deterministic and needs no credentials, which is what lets the
    | whole context run in tests and local development with no external calls.
    | When APP_ENV=testing we force it (see `identity.default`).
    */
    'providers' => [
        'didit' => [
            'enabled' => (bool) env('DIDIT_ENABLED', true),
            'base_url' => env('DIDIT_BASE_URL', 'https://verification.didit.me'),
            'api_key' => env('DIDIT_API_KEY', ''),
            'webhook_secret' => env('DIDIT_WEBHOOK_SECRET', ''),
            'timeout' => (int) env('DIDIT_TIMEOUT', 30),
            'retry_attempts' => (int) env('DIDIT_RETRY_ATTEMPTS', 2),
            'retry_delay_ms' => (int) env('DIDIT_RETRY_DELAY_MS', 250),

            // Replay window for webhooks, in seconds. Didit's own reference
            // implementation rejects anything outside ±300s.
            'replay_tolerance' => (int) env('DIDIT_REPLAY_TOLERANCE', 300),

            /*
            | One workflow per requirement set. A workflow decides which checks
            | Didit actually runs (document, licence, liveness, face match), so
            | "which documents are accepted" is a provider-side configuration
            | choice surfaced here rather than hard-coded in our domain.
            */
            'workflows' => [
                'rider_identity' => env('DIDIT_WORKFLOW_RIDER_IDENTITY', ''),
                'rider_licence' => env('DIDIT_WORKFLOW_RIDER_LICENCE', ''),
                'representative_identity' => env('DIDIT_WORKFLOW_REPRESENTATIVE', ''),
                'customer_identity' => env('DIDIT_WORKFLOW_CUSTOMER_IDENTITY', ''),
                'business' => env('DIDIT_WORKFLOW_BUSINESS', ''),
            ],

            // Where Didit sends the user back after the hosted flow.
            'callback_url' => env('DIDIT_CALLBACK_URL'),
        ],

        'mock' => [
            'enabled' => true,
            'webhook_secret' => env('VERIFICATION_MOCK_WEBHOOK_SECRET', 'mock-webhook-secret'),
            'replay_tolerance' => (int) env('VERIFICATION_MOCK_REPLAY_TOLERANCE', 300),
        ],

        'manual' => [
            'enabled' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Provider routing
    |--------------------------------------------------------------------------
    | Which provider handles which kind of case, per country. `default` applies
    | to any country without an explicit entry, so adding a market is a config
    | change. In testing everything routes to the offline mock.
    */
    'routing' => [
        'identity' => [
            'default' => env('VERIFICATION_IDENTITY_PROVIDER', env('APP_ENV') === 'testing' ? 'mock' : 'didit'),
            'by_country' => [
                // 'NG' => 'didit',
            ],
        ],
        'business' => [
            'default' => env('VERIFICATION_BUSINESS_PROVIDER', env('APP_ENV') === 'testing' ? 'mock' : 'manual'),
            'by_country' => [
                // Nigeria: the CAC registry adapter checks the registration
                // number, then a representative's identity is verified
                // separately through the identity provider.
                'NG' => env('VERIFICATION_BUSINESS_PROVIDER_NG', env('APP_ENV') === 'testing' ? 'mock' : 'cac'),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Business registries (KYB lookup)
    |--------------------------------------------------------------------------
    | Registry lookup is a separate concern from identity verification: it
    | answers "does this company exist and is it active", not "is this person
    | who they claim". Country-keyed so nothing assumes CAC exists everywhere.
    */
    'registries' => [
        'NG' => [
            'adapter' => 'cac',
            'authority' => 'CAC',
            'label' => 'Corporate Affairs Commission',
            // Registration number formats CAC issues: RC (company),
            // BN (business name), IT (incorporated trustees).
            'number_pattern' => '/^(RC|BN|IT)[- ]?\d{4,12}$/i',
            'api' => [
                // Empty until a CAC API contract is provisioned. While unset the
                // adapter validates format and routes to human review rather
                // than pretending to have checked the registry.
                'base_url' => env('CAC_API_BASE_URL', ''),
                'api_key' => env('CAC_API_KEY', ''),
                'timeout' => (int) env('CAC_API_TIMEOUT', 30),
            ],
        ],
    ],

    // Countries the platform currently onboards businesses in. A country with
    // no registry entry falls back to manual review — never to "assume valid".
    'supported_countries' => array_values(array_filter(explode(
        ',',
        (string) env('VERIFICATION_SUPPORTED_COUNTRIES', 'NG'),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Progressive customer verification
    |--------------------------------------------------------------------------
    | Ordinary registration reaches `basic` and is never pushed further. Higher
    | levels are demanded only when an operation or a risk signal calls for it.
    */
    'levels' => [
        'registration_default' => 'basic',
        'rider_required' => 'identity',
        'representative_required' => 'identity',
    ],

    /*
    |--------------------------------------------------------------------------
    | Step-up triggers
    |--------------------------------------------------------------------------
    | Configuration, not code: operations teams can retune thresholds without a
    | deploy. `always` demands the level every time; `above_minor` only when the
    | amount exceeds it; `threshold` when a risk counter reaches it.
    */
    'step_up' => [
        /*
         * Off by default, for the same reason enforcement is: these triggers
         * gate operations existing customers already perform. Switching them on
         * with the deploy would demand identity verification from an account
         * mid-transfer, which is the lockout this milestone is built to avoid.
         * Turn on deliberately, after the population has had a chance to verify.
         */
        'enabled' => (bool) env('VERIFICATION_STEP_UP_ENABLED', false),
        'triggers' => [
            'wallet.transfer' => ['above_minor' => (int) env('STEP_UP_WALLET_TRANSFER_MINOR', 500000), 'level' => 'identity'],
            'wallet.withdraw' => ['always' => true, 'level' => 'identity'],
            'payout.bank_details' => ['always' => true, 'level' => 'identity'],
            'account.email_change' => ['always' => true, 'level' => 'phone'],
            'account.password_change' => ['always' => true, 'level' => 'phone'],
            'account.phone_change' => ['always' => true, 'level' => 'phone'],
            'risk.dispute_count' => ['threshold' => (int) env('STEP_UP_DISPUTE_THRESHOLD', 3), 'level' => 'identity'],
            'risk.suspicious_login' => ['always' => true, 'level' => 'phone'],
            'risk.velocity' => ['threshold' => (int) env('STEP_UP_VELOCITY_THRESHOLD', 10), 'level' => 'phone'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Case lifecycle
    |--------------------------------------------------------------------------
    */
    'lifecycle' => [
        // How long a completed identity verification stays valid before
        // reverification is required. 0 disables expiry.
        'identity_validity_days' => (int) env('VERIFICATION_IDENTITY_VALIDITY_DAYS', 730),
        'business_validity_days' => (int) env('VERIFICATION_BUSINESS_VALIDITY_DAYS', 365),

        // A case stuck in `processing` beyond this is polled by
        // `verification:reconcile` in case the webhook was lost.
        'reconcile_after_minutes' => (int) env('VERIFICATION_RECONCILE_AFTER_MINUTES', 30),

        // Metadata retention after a case closes. The purge command removes
        // document metadata past this; the audit trail is kept.
        'metadata_retention_days' => (int) env('VERIFICATION_METADATA_RETENTION_DAYS', 1825),
    ],

    /*
    |--------------------------------------------------------------------------
    | Phone verification (the `phone` level)
    |--------------------------------------------------------------------------
    */
    'phone' => [
        'code_ttl_seconds' => (int) env('VERIFICATION_PHONE_CODE_TTL', 600),
        'max_attempts' => (int) env('VERIFICATION_PHONE_MAX_ATTEMPTS', 5),
    ],
];
