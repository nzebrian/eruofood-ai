<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Notifications, Messaging & Real-Time Communication configuration
|------------------------------------------------------------------------------
| Tunables for the Notifications bounded context: default language, which
| channels are enabled, the retry policy for failed deliveries, quiet-hours
| default, and the map from published domain events to notifications. No
| business module sends notifications directly — the event subscriber turns
| domain events into notifications using this map.
*/

return [
    'default_language' => env('NOTIFICATIONS_LANG', 'en'),

    // Whether each delivery channel is active. WhatsApp/Telegram ship as
    // architecture-ready senders (disabled by default).
    'channels' => [
        'email' => (bool) env('NOTIFY_EMAIL', true),
        'sms' => (bool) env('NOTIFY_SMS', true),
        'push' => (bool) env('NOTIFY_PUSH', true),
        'in_app' => true, // always on — the notification centre
        'whatsapp' => (bool) env('NOTIFY_WHATSAPP', false),
        'telegram' => (bool) env('NOTIFY_TELEGRAM', false),
    ],

    // Real-time transport (Laravel Reverb/Pusher-style). The default "log"
    // broadcaster is offline-safe for tests/local; swap for "reverb" in prod.
    'realtime' => [
        'driver' => env('NOTIFICATIONS_REALTIME', env('APP_ENV') === 'testing' ? 'log' : 'log'),
    ],

    'retry' => [
        'max_attempts' => (int) env('NOTIFY_RETRY_ATTEMPTS', 3),
        'backoff_seconds' => [60, 300, 900],
    ],

    // Default quiet hours (24h, local) during which only high-priority
    // notifications are delivered immediately; others defer to the window end.
    'quiet_hours' => [
        'enabled_by_default' => false,
        'start' => env('NOTIFY_QUIET_START', '22:00'),
        'end' => env('NOTIFY_QUIET_END', '07:00'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Event → notification map. Each published domain event (by its stable
    | eventName) becomes a notification: the category, the template key, the
    | default channels, and the ordered list of event properties to try when
    | resolving the recipient user id. Events with no resolvable recipient are
    | ignored (or handled as admin/broadcast elsewhere).
    |--------------------------------------------------------------------------
    */
    'event_map' => [
        'identity.user_registered' => ['category' => 'account', 'template' => 'welcome', 'channels' => ['email', 'in_app'], 'recipient' => ['userId']],
        'identity.password_changed' => ['category' => 'account', 'template' => 'password_changed', 'channels' => ['email', 'in_app'], 'recipient' => ['userId']],
        'identity.email_verified' => ['category' => 'account', 'template' => 'email_verified', 'channels' => ['in_app'], 'recipient' => ['userId']],
        'identity.two_factor_enabled' => ['category' => 'account', 'template' => 'two_factor_enabled', 'channels' => ['email', 'in_app'], 'recipient' => ['userId']],
        'commerce.order_placed' => ['category' => 'order', 'template' => 'order_placed', 'channels' => ['push', 'in_app'], 'recipient' => ['customerUserId']],
        'marketplace.order_placed' => ['category' => 'order', 'template' => 'order_placed', 'channels' => ['push', 'in_app'], 'recipient' => ['customerUserId']],
        'payments.payment_succeeded' => ['category' => 'payment', 'template' => 'payment_succeeded', 'channels' => ['push', 'in_app'], 'recipient' => ['payerUserId']],
        'payments.payment_failed' => ['category' => 'payment', 'template' => 'payment_failed', 'channels' => ['email', 'push', 'in_app'], 'recipient' => ['payerUserId']],
        'payments.wallet_credited' => ['category' => 'wallet', 'template' => 'wallet_credited', 'channels' => ['in_app'], 'recipient' => ['ownerId']],
        'payments.wallet_low_balance' => ['category' => 'wallet', 'template' => 'wallet_low_balance', 'channels' => ['push', 'in_app'], 'recipient' => ['ownerId']],
        'payments.settlement_completed' => ['category' => 'payment', 'template' => 'settlement_completed', 'channels' => ['email', 'in_app'], 'recipient' => ['payeeId']],
        'nutrition.health_profile_updated' => ['category' => 'nutrition', 'template' => 'nutrition_profile_updated', 'channels' => ['in_app'], 'recipient' => ['userId']],
    ],
];
