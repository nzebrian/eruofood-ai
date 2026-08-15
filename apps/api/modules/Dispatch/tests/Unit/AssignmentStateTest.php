<?php

declare(strict_types=1);

use EruoFood\Dispatch\Domain\Enum\AssignmentState;
use EruoFood\Dispatch\Domain\Enum\DispatchState;
use EruoFood\Dispatch\Domain\Enum\OfferState;

/**
 * M26 decision 1 — the Dispatch/Marketplace boundary, stated as tests.
 *
 * Marketplace's `Delivery` stays the operational delivery aggregate and owns
 * the journey. Dispatch owns the relationship between a rider and that
 * delivery. Two state machines over one real-world process can contradict each
 * other, and these tests pin the rules that stop them:
 *
 * - the journey advances in one direction only, through an explicit table;
 * - once the rider is carrying the food, reassignment is no longer a dispatch
 *   decision;
 * - the mirror from Marketplace is a projection, never a second place the
 *   journey can be driven from.
 */

it('advances the journey in one direction only', function (): void {
    expect(AssignmentState::Accepted->canTransitionTo(AssignmentState::EnRoutePickup))->toBeTrue()
        ->and(AssignmentState::EnRoutePickup->canTransitionTo(AssignmentState::ArrivedPickup))->toBeTrue()
        ->and(AssignmentState::ArrivedPickup->canTransitionTo(AssignmentState::PickedUp))->toBeTrue()
        ->and(AssignmentState::PickedUp->canTransitionTo(AssignmentState::InTransit))->toBeTrue()
        ->and(AssignmentState::InTransit->canTransitionTo(AssignmentState::Delivered))->toBeTrue();

    // Backwards, and skipping ahead, are both refused.
    expect(AssignmentState::PickedUp->canTransitionTo(AssignmentState::EnRoutePickup))->toBeFalse()
        ->and(AssignmentState::Accepted->canTransitionTo(AssignmentState::Delivered))->toBeFalse()
        ->and(AssignmentState::Accepted->canTransitionTo(AssignmentState::PickedUp))->toBeFalse();
});

/**
 * The pre-M26 `Delivery` used a `+1` ordinal table whose `en_route` sat *after*
 * `picked_up` — the opposite of `EN_ROUTE_PICKUP`. An ordinal comparison cannot
 * be read; an explicit table cannot be wrong quietly. This is the regression.
 */
it('puts travelling to the pickup before collecting the food', function (): void {
    $order = [
        AssignmentState::Accepted,
        AssignmentState::EnRoutePickup,
        AssignmentState::ArrivedPickup,
        AssignmentState::PickedUp,
        AssignmentState::InTransit,
        AssignmentState::Delivered,
    ];

    foreach ($order as $i => $state) {
        $next = $order[$i + 1] ?? null;

        if ($next !== null) {
            expect($state->canTransitionTo($next))->toBeTrue();
        }
    }
});

it('stops allowing reassignment once the rider is carrying the food', function (): void {
    // Before pickup, handing the job to somebody else is an ordinary dispatch
    // decision.
    foreach ([AssignmentState::Accepted, AssignmentState::EnRoutePickup, AssignmentState::ArrivedPickup] as $state) {
        expect($state->canTransitionTo(AssignmentState::ReassignmentRequired))->toBeTrue();
    }

    // After it, the food is in somebody's bag. That is an operational incident,
    // not a reassignment, so only an explicit cancellation can end it.
    foreach ([AssignmentState::PickedUp, AssignmentState::InTransit] as $state) {
        expect($state->canTransitionTo(AssignmentState::ReassignmentRequired))->toBeFalse()
            ->and($state->canTransitionTo(AssignmentState::Cancelled))->toBeTrue();
    }
});

it('cannot move on from a terminal state', function (): void {
    foreach ([AssignmentState::Delivered, AssignmentState::Cancelled, AssignmentState::ReassignmentRequired] as $state) {
        expect($state->isTerminal())->toBeTrue()
            ->and($state->allowedNext())->toBe([])
            ->and($state->isActive())->toBeFalse();
    }
});

/**
 * The application-side mirror of the partial unique indexes on
 * `dispatch_assignments`. If these two ever disagree, the database is enforcing
 * a rule the code does not believe in — or, far worse, the code believes in one
 * the database is not enforcing.
 */
it('treats exactly the non-terminal states as occupying a rider and a delivery', function (): void {
    expect(AssignmentState::occupyingValues())->toBe([
        'accepted',
        'en_route_pickup',
        'arrived_pickup',
        'picked_up',
        'in_transit',
    ]);
});

it('mirrors a Marketplace delivery status without inventing one', function (): void {
    expect(AssignmentState::forDeliveryStatus('picked_up'))->toBe(AssignmentState::PickedUp)
        ->and(AssignmentState::forDeliveryStatus('delivered'))->toBe(AssignmentState::Delivered);

    // A delivery status with no assignment meaning leaves Dispatch's record
    // alone rather than guessing at one.
    expect(AssignmentState::forDeliveryStatus('unassigned'))->toBeNull()
        ->and(AssignmentState::forDeliveryStatus('something_new'))->toBeNull();
});

/**
 * The mirror is one-way by construction: there is no `Accepted` on the
 * Marketplace side to mirror *from*, because acceptance is Dispatch's decision
 * and nothing in Marketplace may create an assignment.
 */
it('offers no route for Marketplace to create an assignment', function (): void {
    foreach (['assigned', 'accepted', 'cancelled', 'reassignment_required'] as $status) {
        expect(AssignmentState::forDeliveryStatus($status))->not->toBe(AssignmentState::Accepted);
    }
});

it('lets exactly one offer state be answered', function (): void {
    $answerable = array_values(array_filter(
        OfferState::cases(),
        static fn (OfferState $s): bool => $s->isAnswerable(),
    ));

    // What makes the partial unique index on (rider_id) WHERE state='offered'
    // meaningful: a rider looks at one offer at a time.
    expect($answerable)->toBe([OfferState::Offered])
        ->and(OfferState::Accepted->isTerminal())->toBeTrue()
        ->and(OfferState::Expired->isTerminal())->toBeTrue();
});

it('lets a dispatch request be claimed only before a worker holds it', function (): void {
    expect(DispatchState::Pending->isClaimable())->toBeTrue();

    // Not `Dispatching`: a request another worker is already working is exactly
    // what a second worker must not pick up, or two pools get built for one
    // delivery and two riders get offered it.
    foreach (DispatchState::cases() as $state) {
        if ($state !== DispatchState::Pending) {
            expect($state->isClaimable())->toBeFalse($state->value);
        }
    }

    expect(DispatchState::Assigned->isTerminal())->toBeTrue()
        ->and(DispatchState::Failed->isTerminal())->toBeTrue()
        ->and(DispatchState::Cancelled->isTerminal())->toBeTrue()
        ->and(DispatchState::Dispatching->isTerminal())->toBeFalse();
});
