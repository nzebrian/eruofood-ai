<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Marketplace, Grocery & Commerce Platform configuration
|------------------------------------------------------------------------------
| Tunables for the general e-commerce / grocery context: default currency,
| listing page sizes, whether new stores & products need admin approval before
| they go live, the tax model, and the flat/threshold shipping model. Kept out
| of the domain so ops can adjust without a code change. Prices are in integer
| minor units (kobo).
*/

return [
    'currency' => env('COMMERCE_CURRENCY', 'NGN'),

    'pagination' => [
        'per_page' => (int) env('COMMERCE_PER_PAGE', 20),
        'max_per_page' => 60,
    ],

    // New stores start unverified and new products start pending; neither is
    // publicly listed until an admin approves (when true).
    'require_verification' => (bool) env('COMMERCE_REQUIRE_VERIFICATION', true),

    /*
    |--------------------------------------------------------------------------
    | Tax — a single VAT-style rate applied to the (post-discount) subtotal.
    | Nigeria's VAT is 7.5%; expressed here in basis points (750 = 7.5%).
    |--------------------------------------------------------------------------
    */
    'tax' => [
        'rate_bps' => (int) env('COMMERCE_TAX_RATE_BPS', 750),
        'inclusive' => (bool) env('COMMERCE_TAX_INCLUSIVE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Shipping — a flat fee plus an optional per-item component, waived once the
    | (post-discount) subtotal crosses the free-shipping threshold. Pickup is
    | always free.
    |--------------------------------------------------------------------------
    */
    'shipping' => [
        'flat_fee' => (int) env('COMMERCE_SHIP_FLAT_FEE', 150000),      // ₦1,500
        'per_item_fee' => (int) env('COMMERCE_SHIP_PER_ITEM_FEE', 0),
        'free_over' => (int) env('COMMERCE_SHIP_FREE_OVER', 2000000),   // free above ₦20,000
    ],

    /*
    |--------------------------------------------------------------------------
    | Inventory — the default low-stock alert threshold and how many days ahead
    | of an expiry date a batch is flagged as "expiring soon".
    |--------------------------------------------------------------------------
    */
    'inventory' => [
        'low_stock_threshold' => (int) env('COMMERCE_LOW_STOCK_THRESHOLD', 10),
        'expiry_warning_days' => (int) env('COMMERCE_EXPIRY_WARNING_DAYS', 14),
    ],
];
