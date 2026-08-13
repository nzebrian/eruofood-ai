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

    /*
    |--------------------------------------------------------------------------
    | Email channel. `driver` selects the provider adapter beneath the channel:
    | `log` records the send and transmits nothing (the default, so a local or
    | CI environment can never email a real customer), `mailer` goes through
    | Laravel's configured mail transport.
    |
    | Credentials live in the mail configuration and its environment. Nothing
    | here, and nothing in any template, holds a secret.
    |--------------------------------------------------------------------------
    */
    'email' => [
        'driver' => env('NOTIFY_EMAIL_DRIVER', 'log'),
        'from_address' => env('NOTIFY_EMAIL_FROM', env('MAIL_FROM_ADDRESS')),
        'from_name' => env('NOTIFY_EMAIL_FROM_NAME', env('MAIL_FROM_NAME', 'EruoFood')),
        'app_name' => env('NOTIFY_APP_NAME', 'EruoFood'),
        // Where an email sends somebody who needs to see more than it says.
        'app_url' => env('NOTIFY_APP_URL', env('APP_FRONTEND_URL', '')),
        'support_address' => env('NOTIFY_SUPPORT_EMAIL', ''),
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

        /*
         * ---- KYC / KYB (M24) ----
         *
         * Every entry here declares `fields`, so *only* the properties named
         * reach the template. Verification events are the one place where a
         * property added later could put regulated data into somebody's inbox,
         * and an allow-list is the difference between that being impossible and
         * merely unlikely.
         *
         * The messages say what happened and where to go. They never say what
         * was wrong with a document, which registry check failed, or what data
         * was read — an inbox may be shared, forwarded, or breached, and none of
         * that belongs in one. `action_path` points at the secure application,
         * which is where the detail lives.
         *
         * Email plus in-app, never SMS or push: these are account-security
         * messages, and the two channels chosen are the ones a person can go
         * back and re-read.
         */
        'verification.submitted' => [
            [
                'when' => ['caseType' => 'identity'],
                'category' => 'verification',
                'template' => 'verification_submitted',
                'channels' => ['email', 'in_app'],
                'recipient' => ['contactUserId'],
                'fields' => ['caseId', 'subjectType', 'caseType'],
                'correlation' => 'caseId',
                'data' => ['action_path' => '/account/verification'],
            ],
            [
                // A merchant's KYB submission — different audience, different
                // wording, same event.
                'when' => ['caseType' => 'business'],
                'category' => 'verification',
                'template' => 'kyb_submitted',
                'channels' => ['email', 'in_app'],
                'recipient' => ['contactUserId'],
                'fields' => ['caseId', 'subjectType', 'caseType'],
                'correlation' => 'caseId',
                'data' => ['action_path' => '/merchant/verification'],
            ],
        ],

        'verification.processing' => [
            'category' => 'verification',
            'template' => 'verification_processing',
            'channels' => ['in_app'],
            'recipient' => ['contactUserId'],
            'fields' => ['caseId', 'subjectType', 'caseType'],
            'correlation' => 'caseId',
            'priority' => 'low',
        ],

        'verification.subject_verified' => [
            [
                // A rider approved is a rider activated: the same moment, and
                // one message that says so rather than two that overlap.
                'when' => ['subjectType' => 'rider'],
                'category' => 'verification',
                'template' => 'rider_verification_approved',
                'channels' => ['email', 'in_app'],
                'recipient' => ['contactUserId'],
                'fields' => ['caseId', 'subjectType', 'caseType', 'expiresAt'],
                'correlation' => 'caseId',
                'data' => ['action_path' => '/rider/status'],
            ],
            [
                'when' => ['caseType' => 'business'],
                'category' => 'verification',
                'template' => 'kyb_approved',
                'channels' => ['email', 'in_app'],
                'recipient' => ['contactUserId'],
                'fields' => ['caseId', 'subjectType', 'caseType', 'expiresAt'],
                'correlation' => 'caseId',
                'data' => ['action_path' => '/merchant/verification'],
            ],
            [
                'when' => ['subjectType' => 'customer'],
                'category' => 'verification',
                'template' => 'verification_approved',
                'channels' => ['email', 'in_app'],
                'recipient' => ['contactUserId'],
                'fields' => ['caseId', 'subjectType', 'caseType'],
                'correlation' => 'caseId',
                'data' => ['action_path' => '/account/verification'],
            ],
        ],

        'verification.subject_rejected' => [
            [
                'when' => ['caseType' => 'business'],
                'category' => 'verification',
                'template' => 'kyb_rejected',
                'channels' => ['email', 'in_app'],
                'recipient' => ['contactUserId'],
                // `retryable` travels because it changes what the merchant should
                // do next. The reason code deliberately does not: what was wrong
                // with a document belongs behind a login.
                'fields' => ['caseId', 'subjectType', 'caseType', 'retryable'],
                'correlation' => 'caseId',
                'data' => ['action_path' => '/merchant/verification'],
            ],
            [
                'category' => 'verification',
                'template' => 'verification_rejected',
                'channels' => ['email', 'in_app'],
                'recipient' => ['contactUserId'],
                'fields' => ['caseId', 'subjectType', 'caseType', 'retryable'],
                'correlation' => 'caseId',
                'data' => ['action_path' => '/account/verification'],
            ],
        ],

        'verification.reverification_required' => [
            'category' => 'verification',
            'template' => 'reverification_required',
            'channels' => ['email', 'in_app'],
            'recipient' => ['contactUserId'],
            'fields' => ['caseId', 'subjectType', 'caseType'],
            'correlation' => 'caseId',
            'priority' => 'high',
            'data' => ['action_path' => '/account/verification'],
        ],
    ],
];
