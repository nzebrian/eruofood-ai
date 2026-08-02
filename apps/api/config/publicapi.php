<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Public API & Developer Platform configuration
|------------------------------------------------------------------------------
| The Public API is a controlled external-facing layer, distinct from the
| internal application APIs. It is authenticated by API keys (never JWT), scoped,
| rate-limited and quota-governed, and versioned under /api/public/{version}. No
| internal endpoint is exposed directly — public controllers return their own
| transformed resources through the standard envelope.
*/

return [
    // Supported public API versions and the current default. Adding v2 later is a
    // matter of appending here and mounting a v2 route group — v1 stays untouched.
    'versions' => ['v1'],
    'current_version' => 'v1',

    // Versions still served but scheduled for removal. Requests to a deprecated
    // version receive Deprecation / Sunset response headers.
    'deprecated' => [
        // 'v1' => ['sunset' => '2027-12-31'],
    ],

    // The catalogue of granted-able scopes. External clients receive only the
    // scopes explicitly granted to their application.
    'scopes' => [
        'foods:read' => 'Read the public food catalogue.',
        'recipes:read' => 'Read published recipes.',
        'restaurants:read' => 'Read restaurant/vendor profiles.',
        'products:read' => 'Read the grocery/product catalogue.',
        'nutrition:read' => 'Read nutrition information.',
        'search:read' => 'Query the public search index.',
        'orders:read' => 'Read orders belonging to the developer/customer.',
        'orders:write' => 'Create and update orders.',
        'webhooks:manage' => 'Manage webhook endpoints (developer portal).',
    ],

    // API key format + hashing.
    'key' => [
        'prefix' => env('PUBLIC_API_KEY_PREFIX', 'efk'),   // e.g. efk_live_XXXX
        'environment_tag' => env('PUBLIC_API_KEY_ENV', 'live'),
        'secret_bytes' => 32,                              // entropy of the secret
        'default_ttl_days' => (int) env('PUBLIC_API_KEY_TTL_DAYS', 0), // 0 = non-expiring
    ],

    // Per-client rate limiting (burst protection) + per-endpoint override support.
    'rate_limit' => [
        'per_minute' => (int) env('PUBLIC_API_RATE_PER_MINUTE', 120),
        'burst' => (int) env('PUBLIC_API_RATE_BURST', 40),
        // Optional stricter per-endpoint caps (route name => per-minute).
        'endpoints' => [
            'public.search.query' => (int) env('PUBLIC_API_SEARCH_PER_MINUTE', 30),
        ],
    ],

    // Daily / monthly request quotas per client.
    'quota' => [
        'daily' => (int) env('PUBLIC_API_QUOTA_DAILY', 10000),
        'monthly' => (int) env('PUBLIC_API_QUOTA_MONTHLY', 200000),
    ],

    // Which Redis/cache store backs counters. Falls back to the array store in
    // tests / when Redis is unavailable (counters are best-effort there).
    'counter_store' => env('PUBLIC_API_COUNTER_STORE', env('CACHE_STORE', 'array')),

    // Standard pagination.
    'pagination' => [
        'default_per_page' => 20,
        'max_per_page' => 100,
    ],

    // Webhook delivery policy.
    'webhooks' => [
        'signature_header' => 'X-EruoFood-Signature',
        'timestamp_header' => 'X-EruoFood-Timestamp',
        'id_header' => 'X-EruoFood-Delivery',
        'replay_tolerance_seconds' => 300,
        'max_attempts' => (int) env('PUBLIC_API_WEBHOOK_MAX_ATTEMPTS', 6),
        // Exponential backoff base (seconds); attempt n waits base * 2^(n-1).
        'backoff_base_seconds' => (int) env('PUBLIC_API_WEBHOOK_BACKOFF', 30),
        'timeout_seconds' => (int) env('PUBLIC_API_WEBHOOK_TIMEOUT', 10),
        // Internal domain events that may be subscribed to, mapped to the public
        // event name delivered to webhooks. Keyed by internal event name.
        'events' => [
            'reviews.review_published' => 'review.published',
            'loyalty.tier_changed' => 'loyalty.tier_changed',
            'commerce.order_paid' => 'order.paid',
            'marketplace.order_placed' => 'order.placed',
        ],
    ],

    // Secure CORS for the public API (browsers). Tighten origins in production.
    'cors' => [
        'allowed_origins' => array_values(array_filter(explode(',', (string) env('PUBLIC_API_CORS_ORIGINS', '')))),
        'allowed_headers' => ['Authorization', 'Content-Type', 'X-Api-Key', 'Idempotency-Key'],
        'max_age' => 600,
    ],
];
