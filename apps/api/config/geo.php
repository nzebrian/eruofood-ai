<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Maps & Geolocation (M25)
|------------------------------------------------------------------------------
| Provider selection, credentials, cache lifetimes, cost controls and the
| routed-pricing switch.
|
| Two things in here are load-bearing and worth reading before changing:
|
| 1. `providers.google.server_key` is a SERVER credential. It is unrestricted by
|    IP in most deployments and must never reach a browser or a mobile binary.
|    The separate `client_key` is the one that ships to clients, and it is
|    expected to carry HTTP-referrer / bundle-id restrictions and to be limited
|    to the map-display and autocomplete APIs.
|
| 2. `pricing.routing_pricing_enabled` decides whether customers are billed on
|    routed road distance or on the pre-M25 straight-line distance. It ships
|    OFF. Turning it on changes real prices, so it is a deliberate act with a
|    documented rollback, not a deployment side effect.
|------------------------------------------------------------------------------
*/

return [
    /*
    |--------------------------------------------------------------------------
    | Provider routing. `capability => provider`, with per-country overrides so
    | a market can use a regional provider without touching the domain.
    |
    | Defaults resolve to `mock` under testing — a real adapter that signs,
    | validates and fails realistically, but never makes a network call. The
    | same arrangement M24 used, for the same reason: the suite must exercise
    | the real code path without depending on somebody else's uptime or budget.
    |--------------------------------------------------------------------------
    */
    'routing' => [
        'geocoding' => [
            'default' => env('GEO_GEOCODING_PROVIDER', env('APP_ENV') === 'testing' ? 'mock' : 'google'),
            'by_country' => [],
        ],
        'routing' => [
            'default' => env('GEO_ROUTING_PROVIDER', env('APP_ENV') === 'testing' ? 'mock' : 'google'),
            'by_country' => [],
        ],
        'places' => [
            'default' => env('GEO_PLACES_PROVIDER', env('APP_ENV') === 'testing' ? 'mock' : 'google'),
            'by_country' => [],
        ],
    ],

    'providers' => [
        'google' => [
            'enabled' => (bool) env('GOOGLE_MAPS_ENABLED', false),

            // SERVER-SIDE ONLY. Never ship this to a client.
            'server_key' => env('GOOGLE_MAPS_SERVER_KEY'),

            // Client-side map display and autocomplete. Expected to be
            // referrer/bundle restricted and API-limited at the Google console.
            // Exposed to browsers and apps by design; it buys an attacker
            // nothing beyond map tiles on our quota.
            'client_key' => env('GOOGLE_MAPS_CLIENT_KEY'),

            'geocoding_url' => env('GOOGLE_MAPS_GEOCODING_URL', 'https://maps.googleapis.com/maps/api/geocode/json'),
            'routes_url' => env('GOOGLE_MAPS_ROUTES_URL', 'https://routes.googleapis.com/directions/v2:computeRoutes'),
            'matrix_url' => env('GOOGLE_MAPS_MATRIX_URL', 'https://routes.googleapis.com/distanceMatrix/v2:computeRouteMatrix'),
            'places_url' => env('GOOGLE_MAPS_PLACES_URL', 'https://places.googleapis.com/v1/places:autocomplete'),

            'timeout_seconds' => (int) env('GOOGLE_MAPS_TIMEOUT', 8),
            'retry_attempts' => (int) env('GOOGLE_MAPS_RETRY_ATTEMPTS', 2),
            'retry_delay_ms' => (int) env('GOOGLE_MAPS_RETRY_DELAY_MS', 250),

            // Biases geocoding towards the launch market without excluding
            // anywhere else.
            'region_bias' => env('GOOGLE_MAPS_REGION_BIAS', 'ng'),
            'language' => env('GOOGLE_MAPS_LANGUAGE', 'en'),
        ],

        'mock' => [
            // Deterministic, offline, and realistic enough to exercise the real
            // decision paths — including failures, which is the part that
            // usually goes untested.
            'seed' => env('GEO_MOCK_SEED', 'eruofood'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache lifetimes, in seconds.
    |
    | The spread is deliberate. An address's coordinates do not change, so a
    | month is fine and every hit is a request not billed. A traffic-aware
    | duration is the opposite: cached for long it stops being traffic-aware and
    | becomes a confidently wrong number, so it gets minutes.
    |--------------------------------------------------------------------------
    */
    'cache' => [
        'enabled' => (bool) env('GEO_CACHE_ENABLED', true),
        'prefix' => env('GEO_CACHE_PREFIX', 'geo'),

        'geocode_ttl' => (int) env('GEO_CACHE_GEOCODE_TTL', 2_592_000),        // 30 days
        'reverse_geocode_ttl' => (int) env('GEO_CACHE_REVERSE_TTL', 604_800),  // 7 days
        'route_ttl' => (int) env('GEO_CACHE_ROUTE_TTL', 86_400),               // 24 hours
        'route_traffic_ttl' => (int) env('GEO_CACHE_ROUTE_TRAFFIC_TTL', 300),  // 5 minutes
        'matrix_ttl' => (int) env('GEO_CACHE_MATRIX_TTL', 3_600),              // 1 hour
        'autocomplete_ttl' => (int) env('GEO_CACHE_AUTOCOMPLETE_TTL', 3_600),  // 1 hour

        // Coordinate rounding used in cache keys. Coarser rounding means more
        // hits and less precision: 5 dp ≈ 1 m for geocoding, 4 dp ≈ 11 m for
        // routes, where a few metres either end changes nothing.
        'coordinate_precision' => (int) env('GEO_CACHE_COORD_PRECISION', 5),
        'route_coordinate_precision' => (int) env('GEO_CACHE_ROUTE_COORD_PRECISION', 4),

        // How old a cached route may be and still be billed when the provider
        // is unreachable. Beyond this the fallback chain moves on rather than
        // charging against a stale answer.
        'stale_route_grace' => (int) env('GEO_STALE_ROUTE_GRACE', 21_600),     // 6 hours
    ],

    /*
    |--------------------------------------------------------------------------
    | Cost control. Mapping APIs bill per request, so a looping mobile client is
    | a financial incident, not just a performance one.
    |--------------------------------------------------------------------------
    */
    'limits' => [
        'geocode_per_user_per_minute' => (int) env('GEO_LIMIT_GEOCODE', 30),
        'route_per_user_per_minute' => (int) env('GEO_LIMIT_ROUTE', 20),
        'autocomplete_per_user_per_minute' => (int) env('GEO_LIMIT_AUTOCOMPLETE', 60),
        'rider_location_per_minute' => (int) env('GEO_LIMIT_RIDER_LOCATION', 30),

        // Platform-wide daily ceiling on billable provider calls. A blunt
        // instrument on purpose: when it trips, quoting degrades rather than
        // the bill growing without limit.
        'provider_daily_quota' => (int) env('GEO_PROVIDER_DAILY_QUOTA', 50_000),
    ],

    'circuit_breaker' => [
        'enabled' => (bool) env('GEO_CIRCUIT_BREAKER_ENABLED', true),
        'failure_threshold' => (int) env('GEO_CIRCUIT_FAILURES', 5),
        'open_seconds' => (int) env('GEO_CIRCUIT_OPEN_SECONDS', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Delivery pricing.
    |
    | OFF by default, and this is the single most consequential switch in M25.
    | Straight-line distance under-states real road distance — commonly by
    | 30–60% in Lagos — so enabling this raises delivery fees for real
    | customers. Deploy the infrastructure, validate it against live traffic
    | with `shadow_mode`, then switch deliberately.
    |
    | `shadow_mode` computes the routed distance and records the comparison
    | without charging for it, so the pricing change can be measured before it
    | is felt.
    |--------------------------------------------------------------------------
    */
    'pricing' => [
        'routing_pricing_enabled' => (bool) env('DELIVERY_ROUTING_PRICING_ENABLED', false),
        'shadow_mode' => (bool) env('DELIVERY_ROUTING_SHADOW_MODE', false),

        // Refuse to quote rather than bill an unverified distance when routing
        // is unavailable and no merchant flat zone fee applies. Turning this
        // off permits the flat fee alone; it never permits a haversine bill.
        'refuse_when_unavailable' => (bool) env('DELIVERY_REFUSE_WHEN_ROUTING_UNAVAILABLE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Geographic defaults. Nigeria is the launch market, not an assumption baked
    | into the domain — every one of these is overridable per deployment.
    |--------------------------------------------------------------------------
    */
    'defaults' => [
        'country_code' => env('GEO_DEFAULT_COUNTRY', 'NG'),
        'search_radius_metres' => (int) env('GEO_DEFAULT_RADIUS_METRES', 10_000),
        'max_radius_metres' => (int) env('GEO_MAX_RADIUS_METRES', 100_000),
        'travel_mode' => env('GEO_DEFAULT_TRAVEL_MODE', 'two_wheeler'),
    ],

    'privacy' => [
        // Decimal places used when coordinates appear in public listings.
        // 3 dp ≈ 110 m: enough to place a merchant on a map, not enough to
        // identify a doorway.
        'public_coordinate_precision' => (int) env('GEO_PUBLIC_COORD_PRECISION', 3),

        // A rider position older than this is not a position, it is history.
        'rider_location_stale_seconds' => (int) env('GEO_RIDER_STALE_SECONDS', 300),
    ],
];
