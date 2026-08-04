<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Loyalty, Rewards & Referrals configuration
|------------------------------------------------------------------------------
| The Loyalty context owns every points balance, tier, reward redemption and
| referral. No business module awards or stores its own points — they publish
| domain events (orders, reviews, referrals) which Loyalty turns into points via
| the earn-rule map below, and they consume the redemption/tier events; they
| never compute a balance or apply a reward themselves.
*/

return [
    // Points expire this many days after they are earned (0 = never). An expiry
    // scan (loyalty:scan-expiry) sweeps ledgers and writes expiry entries.
    'points_expiry_days' => (int) env('LOYALTY_POINTS_EXPIRY_DAYS', 365),

    // Pagination.
    'per_page' => (int) env('LOYALTY_PER_PAGE', 20),

    /*
    |--------------------------------------------------------------------------
    | Membership tiers, ordered lowest → highest. A member sits in the highest
    | tier whose `threshold` (lifetime points earned) they have reached. Tiers
    | are recomputed by the projector whenever a balance changes.
    |--------------------------------------------------------------------------
    */
    'tiers' => [
        ['key' => 'bronze', 'name' => 'Bronze', 'threshold' => 0, 'earn_multiplier' => 1.0],
        ['key' => 'silver', 'name' => 'Silver', 'threshold' => 1000, 'earn_multiplier' => 1.1],
        ['key' => 'gold', 'name' => 'Gold', 'threshold' => 5000, 'earn_multiplier' => 1.25],
        ['key' => 'platinum', 'name' => 'Platinum', 'threshold' => 20000, 'earn_multiplier' => 1.5],
    ],

    /*
    |--------------------------------------------------------------------------
    | Earn rules keyed by external event name. `points` is a flat award; when
    | `per_minor` is set the award is (amount in the named minor field) *
    | per_minor, floored — e.g. 1 point per ₦100 (per_minor = 0.01 on a kobo
    | amount). `user_field`/`amount_field` name the event's public properties.
    | Loyalty subscribes by event name only — it never reaches into a module.
    |--------------------------------------------------------------------------
    */
    'earn_rules' => [
        'commerce.order_paid' => [
            'reason' => 'order',
            'user_field' => 'customerUserId',
            'amount_field' => 'totalMinor',
            'per_minor' => 0.01,
            'points' => 0,
        ],
        'marketplace.order_placed' => [
            'reason' => 'order',
            'user_field' => 'customerUserId',
            'amount_field' => 'totalMinor',
            'per_minor' => 0.01,
            'points' => 0,
        ],
        'reviews.review_published' => [
            'reason' => 'review',
            'user_field' => 'authorId',
            'amount_field' => null,
            'per_minor' => 0.0,
            'points' => 50,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Referral programme. The referrer earns `referrer_points` and the referee
    | `referee_points` when the referee triggers the `qualifying_event` (their
    | first order). Self-referral is always rejected; a referee may be attributed
    | to at most one referrer.
    |--------------------------------------------------------------------------
    */
    'referral' => [
        'referrer_points' => (int) env('LOYALTY_REFERRER_POINTS', 500),
        'referee_points' => (int) env('LOYALTY_REFEREE_POINTS', 250),
        'qualifying_event' => env('LOYALTY_REFERRAL_QUALIFYING_EVENT', 'commerce.order_paid'),
        'qualifying_user_field' => env('LOYALTY_REFERRAL_USER_FIELD', 'customerUserId'),
    ],

    // Prefix for issued redemption codes.
    'redemption_prefix' => env('LOYALTY_REDEMPTION_PREFIX', 'EFR'),
];
