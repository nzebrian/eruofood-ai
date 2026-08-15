<?php

declare(strict_types=1);

use EruoFood\Dispatch\Application\Service\EligibilityService;
use EruoFood\Dispatch\Domain\Candidate\RiderCandidate;
use EruoFood\Dispatch\Domain\Eligibility\Rule\FairnessCapNotReached;
use EruoFood\Dispatch\Domain\Eligibility\Rule\HasDispatchableVehicle;
use EruoFood\Dispatch\Domain\Eligibility\Rule\HasNoConflictingDelivery;
use EruoFood\Dispatch\Domain\Eligibility\Rule\HasNotAlreadyDeclined;
use EruoFood\Dispatch\Domain\Eligibility\Rule\LocationIsAccurate;
use EruoFood\Dispatch\Domain\Eligibility\Rule\LocationIsFresh;
use EruoFood\Dispatch\Domain\Eligibility\Rule\RiderIdentityIsVerified;
use EruoFood\Dispatch\Domain\Eligibility\Rule\RiderIsActive;
use EruoFood\Dispatch\Domain\Eligibility\Rule\RiderIsAvailable;
use EruoFood\Dispatch\Domain\Eligibility\Rule\VehicleDocumentsAreCurrent;
use EruoFood\Dispatch\Domain\Eligibility\Rule\VehicleIsSuitable;
use EruoFood\Dispatch\Domain\Enum\RejectionReason;
use EruoFood\Dispatch\Domain\Enum\VehicleType;
use EruoFood\Dispatch\Domain\Request\DispatchRequest;
use EruoFood\Dispatch\Domain\Vehicle\Vehicle;
use EruoFood\Verification\Contracts\VerificationStatusQuery;

/**
 * M26 — who may be offered a delivery, and why the rest may not.
 *
 * Three properties this file exists to hold:
 *
 * 1. **Mandatory rules cannot be switched off.** Configuration can disable
 *    optional rules per market; naming a mandatory one does nothing at all. A
 *    flag that disables "is this rider legally allowed to drive" is a flag
 *    somebody will eventually set at 2am to clear a backlog.
 * 2. **Every rejection is named and counted once.** "No eligible riders" is
 *    useless at 8pm on a Friday; the breakdown is what separates a platform
 *    outage from a paperwork backlog.
 * 3. **Eligibility runs before scoring**, so no ineligible rider ever costs a
 *    routing call.
 */
const NOW = '2026-06-01 12:00:00';

function verificationThat(bool $blocks): VerificationStatusQuery
{
    return new class ($blocks) implements VerificationStatusQuery {
        public function __construct(private bool $blocks)
        {
        }

        public function statusFor(string $subjectType, string $subjectId): string
        {
            return $this->blocks ? 'not_started' : 'verified';
        }

        public function isVerified(string $subjectType, string $subjectId): bool
        {
            return ! $this->blocks;
        }

        public function levelFor(string $userId): string
        {
            return 'basic';
        }

        public function meetsLevel(string $userId, string $requiredLevel): bool
        {
            return ! $this->blocks;
        }

        public function blocksSubject(string $subjectType, string $subjectId, ?string $businessKind = null): bool
        {
            return $this->blocks;
        }
    };
}

function verifiedVehicle(
    VehicleType $type = VehicleType::Bike,
    ?DateTimeImmutable $insuranceExpiresAt = null,
): Vehicle {
    $at = new DateTimeImmutable('2026-01-01 09:00:00');

    $vehicle = Vehicle::register(
        id: (string) mt_rand(),
        riderId: 'rider-1',
        type: $type,
        now: $at,
        registrationNumber: $type->requiresRegistration() ? 'LAG-'.mt_rand(100, 999) : null,
    );

    if ($insuranceExpiresAt !== null) {
        $vehicle->updateDocuments($insuranceExpiresAt, null, null, $at);
    }

    $vehicle->approve('operator', $at);

    return $vehicle;
}

function candidate(array $overrides = []): RiderCandidate
{
    $defaults = [
        'riderId' => 'rider-1',
        'userId' => 'user-1',
        'riderStatus' => 'online',
        'latitude' => 6.5244,
        'longitude' => 3.3792,
        'straightLineDistanceMetres' => 800.0,
        'locationRecordedAt' => new DateTimeImmutable(NOW),
        'locationAccuracyMetres' => 20.0,
        'vehicles' => [verifiedVehicle()],
        'activeDeliveryCount' => 0,
        'observedAt' => new DateTimeImmutable(NOW),
        'allVehicles' => null,
        'lastAssignedAt' => null,
        'recentAssignmentCount' => 0,
        'consecutiveAssignmentCount' => 0,
    ];

    $values = array_merge($defaults, $overrides);
    $values['allVehicles'] ??= $values['vehicles'];

    return new RiderCandidate(...$values);
}

function dispatchRequest(VehicleType $required = VehicleType::Bike, ?int $loadKg = null): DispatchRequest
{
    return DispatchRequest::open(
        id: 'request-1',
        deliveryId: 'delivery-1',
        orderId: 'order-1',
        vendorId: 'vendor-1',
        pickupLat: 6.5244,
        pickupLng: 3.3792,
        dropoffLat: 6.4531,
        dropoffLng: 3.3958,
        now: new DateTimeImmutable(NOW),
        maxAttempts: 5,
        timeBudgetSeconds: 600,
        requiredVehicleType: $required,
        loadKg: $loadKg,
    );
}

/** The production chain, in production order. */
function fullChain(
    bool $verificationBlocks = false,
    array $switches = [],
    array $declined = [],
    bool $fairnessEnabled = true,
): EligibilityService {
    return new EligibilityService(
        [
            new RiderIsActive(),
            new RiderIsAvailable(),
            new RiderIdentityIsVerified(verificationThat($verificationBlocks)),
            new HasDispatchableVehicle(),
            new VehicleDocumentsAreCurrent(),
            new VehicleIsSuitable(),
            new LocationIsFresh(300),
            new LocationIsAccurate(250.0),
            new HasNoConflictingDelivery(1),
            new HasNotAlreadyDeclined($declined),
            new FairnessCapNotReached($fairnessEnabled, 5),
        ],
        $switches,
    );
}

it('passes a rider who satisfies every rule', function (): void {
    $result = fullChain()->run([candidate()], dispatchRequest(), new DateTimeImmutable(NOW));

    expect($result->hasEligible())->toBeTrue()
        ->and($result->eligibleCount())->toBe(1)
        ->and($result->rejectionBreakdown)->toBe([]);
});

it('rejects a suspended rider and says so', function (): void {
    $result = fullChain()->run([candidate(['riderStatus' => 'suspended'])], dispatchRequest(), new DateTimeImmutable(NOW));

    expect($result->hasEligible())->toBeFalse()
        ->and($result->reasonFor('rider-1'))->toBe(RejectionReason::RiderSuspended);
});

it('tells an offline rider apart from a suspended one', function (): void {
    // A suspension is a decision somebody made; going offline is a shift
    // ending. Collapsing them would make every suspension look like a shift.
    $offline = fullChain()->run([candidate(['riderStatus' => 'offline'])], dispatchRequest(), new DateTimeImmutable(NOW));

    expect($offline->reasonFor('rider-1'))->toBe(RejectionReason::RiderUnavailable);
});

it('rejects a rider M24 says is blocked', function (): void {
    $result = fullChain(verificationBlocks: true)
        ->run([candidate()], dispatchRequest(), new DateTimeImmutable(NOW));

    expect($result->reasonFor('rider-1'))->toBe(RejectionReason::RiderNotVerified);
});

/**
 * The three ways "no usable vehicle" happens, told apart — because they are
 * three different things to tell a rider whose earnings just stopped.
 */
it('distinguishes no vehicle from an unverified one from lapsed paperwork', function (): void {
    $now = new DateTimeImmutable(NOW);

    // The legacy on-foot rider, after the M26 backfill.
    $none = fullChain()->run([candidate(['vehicles' => [], 'allVehicles' => []])], dispatchRequest(), $now);
    expect($none->reasonFor('rider-1'))->toBe(RejectionReason::NoActiveVehicle);

    $unapproved = Vehicle::register(
        id: 'v-2',
        riderId: 'rider-1',
        type: VehicleType::Bike,
        now: new DateTimeImmutable('2026-01-01 09:00:00'),
    );
    $waiting = fullChain()->run(
        [candidate(['vehicles' => [], 'allVehicles' => [$unapproved]])],
        dispatchRequest(),
        $now,
    );
    expect($waiting->reasonFor('rider-1'))->toBe(RejectionReason::VehicleNotVerified);

    $lapsed = verifiedVehicle(insuranceExpiresAt: new DateTimeImmutable('2026-05-01 00:00:00'));
    $expired = fullChain()->run(
        [candidate(['vehicles' => [], 'allVehicles' => [$lapsed]])],
        dispatchRequest(),
        $now,
    );
    expect($expired->reasonFor('rider-1'))->toBe(RejectionReason::VehicleDocumentsExpired);
});

it('rejects a bike for an order needing a car', function (): void {
    $result = fullChain()->run(
        [candidate()],
        dispatchRequest(VehicleType::Car),
        new DateTimeImmutable(NOW),
    );

    expect($result->reasonFor('rider-1'))->toBe(RejectionReason::VehicleUnsuitable);
});

it('accepts a car for an order needing a bike', function (): void {
    $result = fullChain()->run(
        [candidate(['vehicles' => [verifiedVehicle(VehicleType::Car)]])],
        dispatchRequest(VehicleType::Bike),
        new DateTimeImmutable(NOW),
    );

    expect($result->hasEligible())->toBeTrue();
});

it('rejects a bike for a load it cannot carry', function (): void {
    $result = fullChain()->run(
        [candidate()],
        dispatchRequest(VehicleType::Bike, loadKg: 200),
        new DateTimeImmutable(NOW),
    );

    expect($result->reasonFor('rider-1'))->toBe(RejectionReason::VehicleUnsuitable);
});

it('rejects a position too old to dispatch on', function (): void {
    $result = fullChain()->run(
        [candidate(['locationRecordedAt' => new DateTimeImmutable('2026-06-01 11:50:00')])],
        dispatchRequest(),
        new DateTimeImmutable(NOW),
    );

    // Five minutes stale. Dispatching on it sends the order to wherever
    // somebody's phone last had signal.
    expect($result->reasonFor('rider-1'))->toBe(RejectionReason::LocationStale);
});

it('rejects a fix too vague to be a position', function (): void {
    $result = fullChain()->run(
        [candidate(['locationAccuracyMetres' => 2_000.0])],
        dispatchRequest(),
        new DateTimeImmutable(NOW),
    );

    expect($result->reasonFor('rider-1'))->toBe(RejectionReason::LocationInaccurate);
});

it('accepts a device that reports no accuracy at all', function (): void {
    // Plenty of handsets do not supply one; refusing them would exclude working
    // riders for their phone rather than anything about their work.
    $result = fullChain()->run(
        [candidate(['locationAccuracyMetres' => null])],
        dispatchRequest(),
        new DateTimeImmutable(NOW),
    );

    expect($result->hasEligible())->toBeTrue();
});

it('rejects a rider already carrying a delivery', function (): void {
    $result = fullChain()->run(
        [candidate(['activeDeliveryCount' => 1])],
        dispatchRequest(),
        new DateTimeImmutable(NOW),
    );

    expect($result->reasonFor('rider-1'))->toBe(RejectionReason::RiderHasActiveDelivery);
});

it('does not re-offer a delivery a rider already declined', function (): void {
    $result = fullChain(declined: ['rider-1'])
        ->run([candidate()], dispatchRequest(), new DateTimeImmutable(NOW));

    expect($result->reasonFor('rider-1'))->toBe(RejectionReason::AlreadyDeclined);
});

it('sits out a rider who has taken too many in a row', function (): void {
    $result = fullChain()->run(
        [candidate(['consecutiveAssignmentCount' => 5])],
        dispatchRequest(),
        new DateTimeImmutable(NOW),
    );

    expect($result->reasonFor('rider-1'))->toBe(RejectionReason::FairnessCapReached);
});

it('drops the consecutive cap when fairness is switched off', function (): void {
    $result = fullChain(fairnessEnabled: false)->run(
        [candidate(['consecutiveAssignmentCount' => 50])],
        dispatchRequest(),
        new DateTimeImmutable(NOW),
    );

    expect($result->hasEligible())->toBeTrue();
});

/*
|------------------------------------------------------------------------------
| The switches
|------------------------------------------------------------------------------
*/

it('honours a switch that disables an optional rule', function (): void {
    $stale = candidate(['locationRecordedAt' => new DateTimeImmutable('2026-06-01 11:00:00')]);

    expect(fullChain()->run([$stale], dispatchRequest(), new DateTimeImmutable(NOW))->hasEligible())
        ->toBeFalse();

    expect(
        fullChain(switches: ['location_fresh' => false])
            ->run([$stale], dispatchRequest(), new DateTimeImmutable(NOW))
            ->hasEligible(),
    )->toBeTrue();
});

/**
 * The rule this whole design exists for.
 */
it('ignores a switch that tries to disable a mandatory rule', function (): void {
    $now = new DateTimeImmutable(NOW);

    $switches = [
        'rider_identity_verified' => false,
        'vehicle_verified' => false,
        'vehicle_documents_current' => false,
    ];

    // Identity verification stays on.
    expect(
        fullChain(verificationBlocks: true, switches: $switches)
            ->run([candidate()], dispatchRequest(), $now)
            ->hasEligible(),
    )->toBeFalse();

    // So does vehicle verification.
    expect(
        fullChain(switches: $switches)
            ->run([candidate(['vehicles' => [], 'allVehicles' => []])], dispatchRequest(), $now)
            ->hasEligible(),
    )->toBeFalse();

    // And the mandatory rules are all still in the active chain.
    $mandatoryKeys = array_values(array_map(
        static fn ($rule): string => $rule->key(),
        array_filter(fullChain(switches: $switches)->activeRules(), static fn ($r): bool => $r->isMandatory()),
    ));

    expect($mandatoryKeys)->toBe([
        'rider_identity_verified',
        'vehicle_verified',
        'vehicle_documents_current',
    ]);
});

it('matches the mandatory rules documented in configuration', function (): void {
    // config/dispatch.php lists these for an operator reading the file. Read
    // from the file itself rather than the container, so this holds without
    // booting the framework — and so it fails if the file changes, which is
    // the point. If the two disagree, that file is lying about what cannot be
    // switched off.
    $config = require __DIR__.'/../../../../config/dispatch.php';

    expect($config['eligibility']['mandatory_rules'])->toBe([
        'rider_identity_verified',
        'vehicle_verified',
        'vehicle_documents_current',
    ]);
});

/*
|------------------------------------------------------------------------------
| The breakdown
|------------------------------------------------------------------------------
*/

it('counts each rejected rider exactly once, under their first objection', function (): void {
    // This rider fails three rules at once. Counting all three would make the
    // breakdown add up to more riders than exist.
    $result = fullChain()->run([
        candidate([
            'riderStatus' => 'suspended',
            'vehicles' => [],
            'allVehicles' => [],
            'locationRecordedAt' => new DateTimeImmutable('2026-06-01 10:00:00'),
        ]),
    ], dispatchRequest(), new DateTimeImmutable(NOW));

    expect($result->rejectedCount())->toBe(1)
        ->and($result->rejectionBreakdown)->toBe([RejectionReason::RiderSuspended->value => 1]);
});

it('produces the breakdown that tells an outage from a paperwork backlog', function (): void {
    $now = new DateTimeImmutable(NOW);
    $stale = new DateTimeImmutable('2026-06-01 11:00:00');

    $candidates = [];

    for ($i = 0; $i < 9; $i++) {
        $candidates[] = candidate(['riderId' => 'stale-'.$i, 'locationRecordedAt' => $stale]);
    }

    for ($i = 0; $i < 2; $i++) {
        $candidates[] = candidate([
            'riderId' => 'lapsed-'.$i,
            'vehicles' => [],
            'allVehicles' => [verifiedVehicle(insuranceExpiresAt: new DateTimeImmutable('2026-05-01'))],
        ]);
    }

    $result = fullChain()->run($candidates, dispatchRequest(), $now);

    expect($result->hasEligible())->toBeFalse()
        ->and($result->rejectedCount())->toBe(11)
        ->and($result->rejectionBreakdown)->toBe([
            RejectionReason::LocationStale->value => 9,
            RejectionReason::VehicleDocumentsExpired->value => 2,
        ])
        // What an alert leads with.
        ->and($result->dominantRejection())->toBe(RejectionReason::LocationStale);
});

it('answers for one rider, which is what acceptance re-checks', function (): void {
    $chain = fullChain();
    $now = new DateTimeImmutable(NOW);

    expect($chain->reasonAgainst(candidate(), dispatchRequest(), $now))->toBeNull()
        ->and($chain->reasonAgainst(candidate(['riderStatus' => 'suspended']), dispatchRequest(), $now))
        ->toBe(RejectionReason::RiderSuspended);
});
