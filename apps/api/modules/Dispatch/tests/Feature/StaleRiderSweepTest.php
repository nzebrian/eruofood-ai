<?php

declare(strict_types=1);

use EruoFood\Dispatch\Application\Port\RiderPresence;
use EruoFood\Dispatch\Application\Service\AssignmentService;
use EruoFood\Dispatch\Application\Service\DeliveryProgressService;
use EruoFood\Dispatch\Application\Service\StaleRiderSweepService;
use EruoFood\Dispatch\Domain\Assignment\AssignmentRepository;
use EruoFood\Dispatch\Domain\Enum\AssignmentState;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The rider whose phone died, and the delivery they were already carrying.
 *
 * M26 refuses to *offer* work to a stale rider, and an unanswered offer expires
 * on its own deadline. Neither covers an assignment a rider had already
 * accepted: nothing expires it, so the delivery stopped moving in silence until
 * a customer rang.
 */

/** Replace the presence port so "when did we last hear from them" is controllable. */
function presenceIs(array $lastSeen): void
{
    app()->instance(RiderPresence::class, new class ($lastSeen) implements RiderPresence {
        /** @param array<string, DateTimeImmutable|null> $lastSeen */
        public function __construct(private array $lastSeen)
        {
        }

        public function lastSeenAt(string $riderId): ?DateTimeImmutable
        {
            return $this->lastSeen[$riderId] ?? null;
        }
    });

    // Rebuilt so the service picks up the swapped port.
    app()->forgetInstance(StaleRiderSweepService::class);
}

function sweeper(): StaleRiderSweepService
{
    return app(StaleRiderSweepService::class);
}

/** An accepted assignment held by a rider, returned with its ids. */
function heldAssignment(): array
{
    ['userId' => $userId, 'riderId' => $riderId] = sweepRider();
    $request = sweepRequest();
    $offer = staleOffer($request, $riderId, offeredAt: 'now');

    $assignment = app(AssignmentService::class)->accept($userId, $offer->id());

    return ['userId' => $userId, 'riderId' => $riderId, 'assignment' => $assignment];
}

// ------------------------------------------------------------- the safe default

it('does nothing while the feature flag is off', function (): void {
    // This sweep can move a customer's dinner to a different rider unattended,
    // so it ships disabled like every other high-risk capability.
    ['riderId' => $riderId, 'assignment' => $assignment] = heldAssignment();
    presenceIs([$riderId => new DateTimeImmutable('-1 hour')]);

    $result = sweeper()->sweep();

    expect($result['assignments_reassigned'])->toBe(0)
        ->and(app(AssignmentRepository::class)->find($assignment->id())->state())
        ->toBe(AssignmentState::Accepted);
});

it('reports what it would release without changing anything', function (): void {
    // An operator compares this against the live board before switching the
    // sweep on — a threshold slightly too aggressive takes deliveries from
    // riders who are merely in a lift.
    ['riderId' => $riderId, 'assignment' => $assignment] = heldAssignment();
    presenceIs([$riderId => new DateTimeImmutable('-1 hour')]);

    $result = sweeper()->sweep(reportOnly: true);

    expect($result['assignments_reassigned'])->toBe(1)
        ->and($result['examined'])->toBe(1)
        // Reported, not done.
        ->and(app(AssignmentRepository::class)->find($assignment->id())->state())
        ->toBe(AssignmentState::Accepted);
});

// ------------------------------------------------------------------ detection

it('releases a delivery held by a rider who has gone dark', function (): void {
    config()->set('flags.overrides.dispatch.stale_rider_sweep', true);

    ['riderId' => $riderId, 'assignment' => $assignment] = heldAssignment();
    presenceIs([$riderId => new DateTimeImmutable('-1 hour')]);

    $result = sweeper()->sweep();

    expect($result['assignments_reassigned'])->toBe(1)
        ->and(app(AssignmentRepository::class)->find($assignment->id())->state())
        ->not->toBe(AssignmentState::Accepted);
});

it('leaves a rider whose position is current alone', function (): void {
    config()->set('flags.overrides.dispatch.stale_rider_sweep', true);

    ['riderId' => $riderId, 'assignment' => $assignment] = heldAssignment();
    presenceIs([$riderId => new DateTimeImmutable('-10 seconds')]);

    expect(sweeper()->sweep()['assignments_reassigned'])->toBe(0)
        ->and(app(AssignmentRepository::class)->find($assignment->id())->state())
        ->toBe(AssignmentState::Accepted);
});

it('does not treat a rider we have no position for as dark', function (): void {
    // An absent position is a gap in our own data — a rider who has never sent
    // one, or whose record retention purged. Taking work away for our outage is
    // the wrong direction to fail, and it is the judgement
    // AssignmentService::assertStillEligible() already makes.
    config()->set('flags.overrides.dispatch.stale_rider_sweep', true);

    ['assignment' => $assignment] = heldAssignment();
    presenceIs([]);

    expect(sweeper()->sweep()['assignments_reassigned'])->toBe(0)
        ->and(app(AssignmentRepository::class)->find($assignment->id())->state())
        ->toBe(AssignmentState::Accepted);
});

// --------------------------------------------------- the rider holding the food

it('refuses to reassign a rider who already has the customer\'s food', function (): void {
    // Past pickup this is an operational incident with a meal in somebody's
    // bag, not a dispatch decision. M26's transition table refuses it and the
    // sweep respects that rather than working around it.
    config()->set('flags.overrides.dispatch.stale_rider_sweep', true);

    ['userId' => $userId, 'riderId' => $riderId, 'assignment' => $assignment] = heldAssignment();

    $progress = app(DeliveryProgressService::class);
    foreach ([
        AssignmentState::EnRoutePickup,
        AssignmentState::ArrivedPickup,
        AssignmentState::PickedUp,
    ] as $state) {
        $progress->advance($userId, $assignment->id(), $state);
    }

    presenceIs([$riderId => new DateTimeImmutable('-1 hour')]);

    $result = sweeper()->sweep();

    expect($result['held_past_pickup'])->toBe(1)
        ->and($result['assignments_reassigned'])->toBe(0)
        // The rider still holds it — surfaced to operations, not silently undone.
        ->and(app(AssignmentRepository::class)->find($assignment->id())->state())
        ->toBe(AssignmentState::PickedUp);
});

it('uses the same staleness threshold as the dispatch eligibility rule', function (): void {
    // Two different numbers would leave a window where a rider was too stale to
    // be offered work but not stale enough to have theirs released.
    expect(config('geo.privacy.rider_location_stale_seconds'))->toBe(300);
});
