<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Analytics, Business Intelligence & Reporting configuration
|------------------------------------------------------------------------------
| Tunables for the Analytics bounded context: the default reporting currency,
| default dashboard range, retention for raw events, and the map from published
| domain events to the metrics they feed. No module writes into analytics — the
| event subscriber collects domain events and this map decides how each becomes
| a metric.
|
| Each event_map entry:
|   metric     — the metric key incremented (e.g. "revenue", "orders").
|   category   — the analytics category (revenue|sales|orders|customers|…).
|   op         — "count" (increment by 1) or "sum" (add value_key's amount).
|   value_key  — for op=sum, the event property holding the numeric amount.
|   dimensions — event property names captured as breakdown dimensions.
*/

return [
    'currency' => env('ANALYTICS_CURRENCY', 'NGN'),

    'dashboard' => [
        'default_days' => (int) env('ANALYTICS_DEFAULT_DAYS', 30),
    ],

    // Raw event retention (days) before roll-up-only. 0 = keep forever.
    'retention_days' => (int) env('ANALYTICS_RETENTION_DAYS', 400),

    'export' => [
        // Max rows a synchronous export/report may return.
        'max_rows' => (int) env('ANALYTICS_EXPORT_MAX_ROWS', 50000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Event → metric map. The projection pipeline reads this to turn each
    | collected domain event into metric increments. Keys are the events'
    | stable names (never imported classes).
    |--------------------------------------------------------------------------
    */
    'event_map' => [
        'identity.user_registered' => ['metric' => 'customers', 'category' => 'customers', 'op' => 'count', 'dimensions' => []],
        'commerce.order_placed' => ['metric' => 'orders', 'category' => 'orders', 'op' => 'count', 'dimensions' => []],
        'marketplace.order_placed' => ['metric' => 'orders', 'category' => 'orders', 'op' => 'count', 'dimensions' => ['vendorId']],
        'commerce.product_published' => ['metric' => 'products', 'category' => 'products', 'op' => 'count', 'dimensions' => ['storeId']],
        'catalog.recipe_published' => ['metric' => 'recipes', 'category' => 'products', 'op' => 'count', 'dimensions' => []],
        'payments.payment_succeeded' => ['metric' => 'revenue', 'category' => 'revenue', 'op' => 'sum', 'value_key' => 'amountMinor', 'dimensions' => ['provider']],
        'payments.payment_failed' => ['metric' => 'failed_payments', 'category' => 'financial', 'op' => 'count', 'dimensions' => []],
        'payments.refund_completed' => ['metric' => 'refunds', 'category' => 'financial', 'op' => 'sum', 'value_key' => 'amountMinor', 'dimensions' => []],
        'payments.settlement_completed' => ['metric' => 'settlements', 'category' => 'financial', 'op' => 'sum', 'value_key' => 'netMinor', 'dimensions' => ['payeeType']],
        'ai.request_completed' => ['metric' => 'ai_tokens', 'category' => 'ai', 'op' => 'sum', 'value_key' => 'totalTokens', 'dimensions' => ['provider', 'model', 'feature']],
        'nutrition.health_profile_updated' => ['metric' => 'nutrition_updates', 'category' => 'nutrition', 'op' => 'count', 'dimensions' => []],
        'notifications.dispatched' => ['metric' => 'notifications', 'category' => 'operational', 'op' => 'count', 'dimensions' => ['channel']],
    ],
];
