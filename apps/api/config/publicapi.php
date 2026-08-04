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

    // OAuth2 authorization server. Layered on the same scope model as API keys:
    // every issued token carries a scope set (and, for user-delegated grants, a
    // subject user id) so scope enforcement and BOLA are identical to the key
    // path. Tokens and codes are stored only as hashes. TTLs are in seconds.
    'oauth' => [
        'access_ttl' => (int) env('PUBLIC_API_OAUTH_ACCESS_TTL', 3600),          // 1 hour
        'refresh_ttl' => (int) env('PUBLIC_API_OAUTH_REFRESH_TTL', 2_592_000),   // 30 days
        'code_ttl' => (int) env('PUBLIC_API_OAUTH_CODE_TTL', 300),               // 5 minutes
        'access_prefix' => 'efoat_',   // opaque access token
        'refresh_prefix' => 'efort_',  // opaque refresh token
        'code_prefix' => 'efoac_',     // opaque authorization code
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

        // SSRF / egress policy for webhook destinations. The URL guard enforces
        // this both when an endpoint is registered and again immediately before
        // every delivery (DNS-rebinding protection). See WEBHOOKS.md for the
        // infrastructure egress controls this must be paired with in production.
        'security' => [
            // HTTP is tolerated only outside production to ease local testing.
            'allowed_schemes' => array_values(array_filter(explode(',', (string) env(
                'PUBLIC_API_WEBHOOK_SCHEMES',
                env('APP_ENV', 'production') === 'production' ? 'https' : 'https,http',
            )))),
            'enforce_https' => (bool) env('PUBLIC_API_WEBHOOK_ENFORCE_HTTPS', env('APP_ENV', 'production') === 'production'),
            'allowed_ports' => array_map('intval', array_values(array_filter(explode(',', (string) env('PUBLIC_API_WEBHOOK_PORTS', '443,80'))))),
            'block_private_networks' => (bool) env('PUBLIC_API_WEBHOOK_BLOCK_PRIVATE', true),
            // Optional explicit host allowlist (comma-separated); empty = allow any public host.
            'allowed_hosts' => array_values(array_filter(explode(',', (string) env('PUBLIC_API_WEBHOOK_ALLOWED_HOSTS', '')))),
            'connect_timeout_seconds' => (int) env('PUBLIC_API_WEBHOOK_CONNECT_TIMEOUT', 5),
            'max_response_bytes' => (int) env('PUBLIC_API_WEBHOOK_MAX_RESPONSE_BYTES', 65536),
        ],

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
