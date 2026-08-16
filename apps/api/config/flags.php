<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Feature flags — high-risk capabilities and their rollout
|------------------------------------------------------------------------------
| Declarations live in code (see SharedServiceProvider, where each flag is
| registered with its owner, rollout and rollback). This file holds the two
| things that change per environment: the override switches, and the rollout
| rules.
|
| IMPORTANT — this file does not enable anything on its own. Every flag's
| resting state is the `safeDefault` declared alongside it, and for every
| high-risk capability that is `false`. An empty `overrides` and an empty
| `rollout` block means the platform behaves exactly as it did before flags
| existed.
*/

return [

    /*
    | Absolute on/off, evaluated before every other rule — the incident lever.
    |
    | Unset (null) means "no override, fall through to the rollout rules". That
    | is deliberately different from an explicit `false`, which stops evaluation
    | dead. Do not replace these with `env(..., false)`: a default of `false`
    | would make every flag permanently overridden to off and silently disable
    | the rollout rules below.
    */
    'overrides' => [
        // M26 dispatch. Left unset here so the milestone's own switch
        // (DISPATCH_ENGINE_ENABLED, config/dispatch.php) remains the single
        // authority for it. Adding an override that defaults to anything would
        // create a second, competing way to turn automatic dispatch on.
        'dispatch.engine' => env('FLAG_DISPATCH_ENGINE'),

        // M25 routed pricing. Same reasoning: config/geo.php still owns it.
        'pricing.routed' => env('FLAG_PRICING_ROUTED'),

        // Introduced by this foundation. Off until an operator turns it on.
        'dispatch.stale_rider_sweep' => env('FLAG_DISPATCH_STALE_RIDER_SWEEP'),
        'notifications.retry' => env('FLAG_NOTIFICATIONS_RETRY'),
        'lifecycle.retention_purge' => env('FLAG_LIFECYCLE_RETENTION_PURGE'),

        // Reserved for M27+. Declared now so the seam exists and the default is
        // visibly off; the capabilities behind them do not exist yet.
        'payments.orchestrator' => env('FLAG_PAYMENTS_ORCHESTRATOR'),
        'settlement.new_flow' => env('FLAG_SETTLEMENT_NEW_FLOW'),
    ],

    /*
    | Controlled rollout, evaluated only when no override is set.
    |
    | Per flag:
    |   'merchants' => ['<merchant-id>', ...]   explicit allow-list
    |   'countries' => ['NG', 'GH']             ISO-3166 alpha-2, upper-case
    |   'regions'   => ['lagos', 'abuja']
    |   'users'     => ['<user-id>', ...]       for staff dogfooding
    |   'percentage'=> 0-100                    stable per (flag, subject)
    |
    | A named match wins immediately. Percentage applies only when the caller
    | supplied a subject identity — a background job has none, and is therefore
    | never swept into a percentage rollout.
    */
    'rollout' => [
        // Empty on purpose. Nothing is being rolled out by this change.
    ],

];
