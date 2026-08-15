<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Dispatch, Vehicles & Rider Assignment (M26)
|------------------------------------------------------------------------------
| Every number that decides *who gets work* lives here rather than in code,
| because these are operational levers an operations team must be able to turn
| without a deploy — and because a weight buried in a service is a weight nobody
| can audit.
|
| Two things in this file are deliberately not configurable, and both are noted
| again where they are enforced:
|
| 1. The mandatory eligibility rules (identity verification, vehicle
|    verification, document currency). A flag that switches off "is this rider
|    legally allowed to drive" is a flag somebody will eventually set.
|
| 2. Fairness is bounded. It reorders candidates; it can never send a delivery
|    to a rider twelve kilometres away when one is five hundred metres away.
|------------------------------------------------------------------------------
*/

return [
    /*
    |--------------------------------------------------------------------------
    | Candidate discovery
    |
    | The search starts small and widens only as far as it must. An unbounded
    | search is how a dispatch engine ends up loading every rider in the country
    | into memory to deliver one plate of rice.
    |--------------------------------------------------------------------------
    */
    'discovery' => [
        'initial_radius_metres' => (int) env('DISPATCH_INITIAL_RADIUS', 3_000),
        'max_radius_metres' => (int) env('DISPATCH_MAX_RADIUS', 15_000),
        'radius_expansion_factor' => (float) env('DISPATCH_RADIUS_EXPANSION', 2.0),

        // Stop widening once this many eligible riders are found.
        'min_pool_size' => (int) env('DISPATCH_MIN_POOL', 3),

        // Hard ceiling on how many candidates are scored and routed. Each one
        // beyond this costs a provider call and buys almost nothing: the
        // twenty-sixth nearest rider is not going to win.
        'max_pool_size' => (int) env('DISPATCH_MAX_POOL', 25),

        // How many raw positions the geographic prefilter may return before
        // eligibility thins them out.
        'max_raw_candidates' => (int) env('DISPATCH_MAX_RAW_CANDIDATES', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scoring weights. They need not sum to 1 — the service normalises — but
    | keeping them close to 1 makes the numbers readable in a score breakdown.
    |
    | Every offer stores the breakdown these produced. A scoring system whose
    | decisions cannot be explained afterwards is one nobody can debug, and one
    | no rider can be given an honest answer about.
    |--------------------------------------------------------------------------
    */
    'scoring' => [
        'weights' => [
            'proximity' => (float) env('DISPATCH_WEIGHT_PROXIMITY', 0.30),
            'eta' => (float) env('DISPATCH_WEIGHT_ETA', 0.25),
            'vehicle_suitability' => (float) env('DISPATCH_WEIGHT_VEHICLE', 0.15),
            'workload' => (float) env('DISPATCH_WEIGHT_WORKLOAD', 0.10),
            'performance' => (float) env('DISPATCH_WEIGHT_PERFORMANCE', 0.10),
            'acceptance_rate' => (float) env('DISPATCH_WEIGHT_ACCEPTANCE', 0.05),
            'zone_affinity' => (float) env('DISPATCH_WEIGHT_ZONE', 0.05),
        ],

        // Used to turn raw metres and seconds into a 0–1 score. A journey at or
        // beyond these bounds scores zero on that factor rather than negative.
        'normalisation' => [
            'max_distance_metres' => (int) env('DISPATCH_NORM_DISTANCE', 15_000),
            'max_eta_seconds' => (int) env('DISPATCH_NORM_ETA', 3_600),
            'max_active_deliveries' => (int) env('DISPATCH_NORM_WORKLOAD', 3),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fairness.
    |
    | Without this, the nearest high performer takes every order in their area,
    | earns more, scores higher, and takes more. That loop starves everyone else
    | and eventually burns out the person winning it.
    |
    | Applied as a multiplier on the final score, never as an eligibility rule:
    | a fairness penalty should reorder candidates, not make a rider
    | undispatchable.
    |--------------------------------------------------------------------------
    */
    'fairness' => [
        'enabled' => (bool) env('DISPATCH_FAIRNESS_ENABLED', true),

        // The most fairness may ever reduce a score.
        //
        // Clamped at construction: `max_penalty + idle_boost` can never exceed
        // the proximity weight above. If fairness could swing further than
        // distance is worth, it could overturn any distance gap at all — and a
        // rider twelve kilometres away would beat one five hundred metres away,
        // which an earlier draft of this file did. Set it higher than the
        // proximity weight and it is scaled down, not honoured.
        'max_penalty' => (float) env('DISPATCH_FAIRNESS_MAX_PENALTY', 0.20),

        // Assignments inside this window count towards the recency penalty.
        'recent_window_seconds' => (int) env('DISPATCH_FAIRNESS_WINDOW', 3_600),
        'penalty_per_recent_assignment' => (float) env('DISPATCH_FAIRNESS_PER_ASSIGNMENT', 0.08),

        // A rider who has taken this many in a row is skipped for one round.
        'consecutive_assignment_cap' => (int) env('DISPATCH_FAIRNESS_CONSECUTIVE_CAP', 5),

        // A rider offered nothing for this long gets a boost, so a quiet corner
        // of the map does not become a dead one. A rider who has never been
        // assigned anything gets it too — it is what makes a first delivery
        // reachable. Counts towards the clamped swing above.
        'idle_boost_after_seconds' => (int) env('DISPATCH_FAIRNESS_IDLE_AFTER', 1_800),
        'idle_boost' => (float) env('DISPATCH_FAIRNESS_IDLE_BOOST', 0.10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Offers and reassignment
    |--------------------------------------------------------------------------
    */
    'offer' => [
        // Long enough for a rider to notice and read it; short enough that a
        // customer is not waiting on somebody who put their phone down.
        'ttl_seconds' => (int) env('DISPATCH_OFFER_TTL', 45),

        // How many riders are offered at once. One is respectful of riders and
        // slower; several is faster and wastes people's attention. One is the
        // default and the honest starting point.
        'concurrent_offers' => (int) env('DISPATCH_CONCURRENT_OFFERS', 1),
    ],

    'reassignment' => [
        'max_attempts' => (int) env('DISPATCH_MAX_ATTEMPTS', 5),

        // After this the request is failed and handed to operations rather than
        // cycling forever while a customer waits.
        'max_duration_seconds' => (int) env('DISPATCH_MAX_DURATION', 600),

        // A rider who declined this request is not offered it again. Re-asking
        // wastes their time and delays the customer.
        'exclude_decliners' => (bool) env('DISPATCH_EXCLUDE_DECLINERS', true),

        // When a rider drops out, the replacement search inherits what is left
        // of the original deadline rather than a fresh grant — the customer has
        // already been waiting. Below this much remaining time, no replacement
        // search is opened at all: one that fails in twenty seconds wastes the
        // pool's attention and delays the honest answer, which is that this
        // delivery needs a human.
        'minimum_budget_seconds' => (int) env('DISPATCH_MIN_REASSIGN_BUDGET', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Eligibility.
    |
    | Optional rules may be disabled per market. The mandatory ones are listed
    | here for documentation only — `EligibilityService` ignores any attempt to
    | disable them, and a test asserts that.
    |--------------------------------------------------------------------------
    */
    'eligibility' => [
        'optional_rules' => [
            'location_fresh' => (bool) env('DISPATCH_RULE_LOCATION_FRESH', true),
            'location_accurate' => (bool) env('DISPATCH_RULE_LOCATION_ACCURATE', true),
            'within_service_area' => (bool) env('DISPATCH_RULE_SERVICE_AREA', true),
            'no_conflicting_delivery' => (bool) env('DISPATCH_RULE_NO_CONFLICT', true),
        ],

        // Cannot be disabled. Listed so an operator reading this file knows
        // they exist and knows why they are not switches.
        'mandatory_rules' => [
            'rider_identity_verified',
            'vehicle_verified',
            'vehicle_documents_current',
        ],

        'max_accuracy_metres' => (float) env('DISPATCH_MAX_ACCURACY_METRES', 250.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Vehicles
    |--------------------------------------------------------------------------
    */
    'vehicles' => [
        // A rider may register more than one, but only one is primary and only
        // verified ones can be dispatched on.
        'max_per_rider' => (int) env('DISPATCH_MAX_VEHICLES_PER_RIDER', 3),

        // Documents expiring within this window are surfaced to the rider so a
        // lapse is a warning rather than a surprise loss of work.
        'expiry_warning_days' => (int) env('DISPATCH_EXPIRY_WARNING_DAYS', 14),
    ],

    /*
    |--------------------------------------------------------------------------
    | The dispatch engine's own activation switch.
    |
    | OFF by default, exactly as M25's routed pricing was. Turning it on changes
    | how work reaches riders across the whole platform; the existing manual
    | vendor assignment keeps working either way, so this is reversible without
    | a deploy.
    |--------------------------------------------------------------------------
    */
    'engine' => [
        'enabled' => (bool) env('DISPATCH_ENGINE_ENABLED', false),

        // Manual assignment by a vendor or admin. Retained as a break-glass
        // path even when the engine is on, and audited every time it is used.
        'allow_manual_override' => (bool) env('DISPATCH_ALLOW_MANUAL_OVERRIDE', true),
    ],
];
