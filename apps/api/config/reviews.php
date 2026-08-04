<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Reviews & Ratings configuration
|------------------------------------------------------------------------------
| The Reviews context owns every review and the authoritative rating summary for
| each subject. No business module stores or aggregates its own reviews — they
| publish order events (for verified-purchase eligibility) and consume the
| published rating-summary event; they never compute ratings themselves.
*/

return [
    // Moderation posture: 'pre' holds every review for moderation before it is
    // visible; 'post' auto-publishes and relies on the content filter + reports.
    'moderation' => env('REVIEWS_MODERATION', 'post'),

    // Run the content moderator on submission. On a hit under 'post' moderation
    // the review is held (pending) instead of auto-published.
    'content_filter' => (bool) env('REVIEWS_CONTENT_FILTER', true),

    // Use the AI engine (published contract) for content moderation when enabled;
    // otherwise the offline word-list moderator is used.
    'ai_moderation' => (bool) env('REVIEWS_AI_MODERATION', false),

    // A blocked word list for the offline moderator (comma-separated env adds to it).
    'blocklist' => array_values(array_filter(array_merge(
        ['scam', 'fraud', 'idiot', 'garbage'],
        explode(',', (string) env('REVIEWS_BLOCKLIST', '')),
    ))),

    // Max photos per review.
    'max_photos' => (int) env('REVIEWS_MAX_PHOTOS', 6),

    // Pagination.
    'per_page' => (int) env('REVIEWS_PER_PAGE', 20),

    /*
    |--------------------------------------------------------------------------
    | The subject types that may be reviewed. Each maps to the interaction the
    | verified-purchase check looks for.
    |--------------------------------------------------------------------------
    */
    'subjects' => ['product', 'food', 'recipe', 'vendor', 'restaurant', 'rider'],

    /*
    |--------------------------------------------------------------------------
    | Order/interaction events the verified-purchase ledger ingests. Value is
    | the subject type the event grants eligibility for, plus which event field
    | carries the subject id. Like other event-driven contexts, Reviews
    | subscribes by event name — it never reaches into a module.
    |--------------------------------------------------------------------------
    */
    'eligibility_events' => [
        'marketplace.order_placed' => ['subject_type' => 'vendor', 'subject_field' => 'vendorId', 'user_field' => 'customerUserId'],
    ],
];
