<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Restaurant, Vendor & Food Business Platform configuration
|------------------------------------------------------------------------------
| Tunables for the marketplace: default currency, delivery-fee model, listing
| page sizes, and whether new vendors require admin verification before they
| can trade. Kept out of the domain so ops can adjust without a code change.
*/

return [
    'currency' => env('MARKETPLACE_CURRENCY', 'NGN'),

    'pagination' => [
        'per_page' => (int) env('MARKETPLACE_PER_PAGE', 20),
        'max_per_page' => 60,
    ],

    // New vendors start unverified and cannot publish/trade until an admin
    // verifies them (when true).
    'require_verification' => (bool) env('MARKETPLACE_REQUIRE_VERIFICATION', true),

    /*
    |--------------------------------------------------------------------------
    | Delivery fees (minor units, e.g. kobo). A flat base fee plus a per-km
    | component, capped. Zone-specific overrides live on each vendor.
    |--------------------------------------------------------------------------
    */
    'delivery' => [
        'base_fee' => (int) env('MARKETPLACE_DELIVERY_BASE_FEE', 50000),      // ₦500
        'per_km_fee' => (int) env('MARKETPLACE_DELIVERY_PER_KM_FEE', 8000),   // ₦80/km
        'max_fee' => (int) env('MARKETPLACE_DELIVERY_MAX_FEE', 300000),       // ₦3000
        'free_over' => (int) env('MARKETPLACE_DELIVERY_FREE_OVER', 1000000),  // free above ₦10,000
    ],

    // Geospatial search: default radius (km) for "restaurants near me".
    'search' => [
        'default_radius_km' => (float) env('MARKETPLACE_SEARCH_RADIUS_KM', 10.0),
    ],
];
