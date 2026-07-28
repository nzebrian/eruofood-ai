<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Customer Support, Helpdesk & CRM configuration
|------------------------------------------------------------------------------
| The Support context owns every ticket, case, SLA, knowledge-base article and
| customer timeline. No business module manages tickets directly — they publish
| domain events, and Support builds the CRM timeline and drives automation from
| them. All support interactions flow through the Support Domain.
*/

return [
    // Ticket reference prefix, e.g. "EF-000123".
    'ref_prefix' => env('SUPPORT_REF_PREFIX', 'EF'),

    // Whether the AI assistant (summaries, suggested replies, insights) is on.
    // Off by default so the default path stays offline; when on it uses the AI
    // engine's published contract.
    'ai_assist' => (bool) env('SUPPORT_AI_ASSIST', false),

    /*
    |--------------------------------------------------------------------------
    | Default SLA policies by priority: minutes to first response and to
    | resolution. Seeded into support_sla_policies; a ticket resolves its policy
    | by priority at creation and its due-times are computed from these.
    |--------------------------------------------------------------------------
    */
    'sla' => [
        'urgent' => ['first_response' => 30, 'resolution' => 240],
        'high' => ['first_response' => 120, 'resolution' => 480],
        'normal' => ['first_response' => 240, 'resolution' => 1440],
        'low' => ['first_response' => 480, 'resolution' => 2880],
    ],

    // On a resolution-SLA breach, bump the ticket one priority level and notify.
    'escalate_on_breach' => (bool) env('SUPPORT_ESCALATE_ON_BREACH', true),

    // CSAT survey is offered when a ticket is resolved/closed.
    'csat_enabled' => (bool) env('SUPPORT_CSAT_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Domain events the Support CRM ingests to build the unified customer
    | timeline (and to trigger automation). Like other event-driven contexts,
    | Support subscribes by event name — it never reaches into a module.
    | Value: the interaction "kind" recorded on the timeline.
    |--------------------------------------------------------------------------
    */
    'timeline_events' => [
        'identity.user_registered' => 'account.registered',
        'commerce.order_placed' => 'order.placed',
        'marketplace.order_placed' => 'order.placed',
        'payments.payment_succeeded' => 'payment.succeeded',
        'payments.payment_failed' => 'payment.failed',
    ],

    // Customer segmentation thresholds (order count → segment).
    'segments' => [
        'vip' => 20,
        'loyal' => 5,
        'active' => 1,
        'new' => 0,
    ],
];
