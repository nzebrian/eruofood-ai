<?php

declare(strict_types=1);

use EruoFood\Dispatch\Application\Port\CandidateSource;
use EruoFood\Dispatch\Application\Service\CandidateDiscoveryService;
use EruoFood\Dispatch\Application\Service\EligibilityService;
use EruoFood\Dispatch\Domain\Candidate\RiderCandidate;
use EruoFood\Dispatch\Domain\Eligibility\Rule\RiderIsAvailable;
use EruoFood\Dispatch\Domain\Enum\RejectionReason;
use EruoFood\Dispatch\Domain\Request\DispatchRequest;

/**
 * M26 — searching no wider than necessary, and stopping.
 *
 * Starting at the maximum radius would find the most riders and be the wrong
 * thing to do: every extra candidate costs eligibility work and, once scoring
 * runs, a routed ETA from a paid provider. Searching fifteen kilometres to
 * deliver a plate of rice five hundred metres away is how a dispatch engine
 * becomes the most expensive part of an order.
 *
 * Three separate stopping conditions are tested, because all three are load
 * bearing: the radius ceiling, the pool-size floor, and the iteration guard
 * that survives a misconfigured expansion factor.
 */

/** A source that answers from a fixed map of radius => candidates. */
function sourceReturning(array $byRadius, ?array $log = null): CandidateSource
{
    return new class ($byRadius, $log) implements CandidateSource {
        /** @var list<float> */
        public array $radiiSearched = [];

        public function __construct(private array $byRadius, private ?array $log)
        {
        }

        public function near(DispatchRequest $request, float $radiusMetres, int $limit, DateTimeImmutable $now): array
        {
            $this->radiiSearched[] = $radiusMetres;

            $best = [];

            foreach ($this->byRadius as $threshold => $candidates) {
                if ($radiusMetres >= (float) $threshold) {
                    $best = $candidates;
                }
            }

            return array_slice($best, 0, $limit);
        }

        public function forRider(string $riderId, DispatchRequest $request, DateTimeImmutable $now): ?RiderCandidate
        {
            // Discovery never calls this; the acceptance-time re-check does.
            return null;
        }
    };
}

function riderAt(string $id, string $status = 'online'): RiderCandidate
{
    return new RiderCandidate(
        riderId: $id,
        userId: 'user-'.$id,
        riderStatus: $status,
        latitude: 6.5,
        longitude: 3.3,
        straightLineDistanceMetres: 500.0,
        locationRecordedAt: new DateTimeImmutable('2026-06-01 12:00:00'),
        locationAccuracyMetres: 20.0,
        vehicles: [],
        activeDeliveryCount: 0,
        observedAt: new DateTimeImmutable('2026-06-01 12:00:00'),
    );
}

function discoveryRequest(): DispatchRequest
{
    return DispatchRequest::open(
        id: 'r-1',
        deliveryId: 'd-1',
        orderId: 'o-1',
        vendorId: 'v-1',
        pickupLat: 6.5,
        pickupLng: 3.3,
        dropoffLat: 6.4,
        dropoffLng: 3.4,
        now: new DateTimeImmutable('2026-06-01 12:00:00'),
        maxAttempts: 5,
        timeBudgetSeconds: 600,
    );
}

/** Availability only, so the tests exercise discovery rather than the rule chain. */
function availabilityOnly(): EligibilityService
{
    return new EligibilityService([new RiderIsAvailable()]);
}

function discovery(
    CandidateSource $source,
    float $initial = 3_000,
    float $max = 15_000,
    float $factor = 2.0,
    int $minPool = 3,
    int $maxPool = 25,
): CandidateDiscoveryService {
    return new CandidateDiscoveryService(
        $source,
        availabilityOnly(),
        $initial,
        $max,
        $factor,
        $minPool,
        $maxPool,
        100,
    );
}

it('stops at the first radius that finds enough riders', function (): void {
    $source = sourceReturning([3_000 => [riderAt('a'), riderAt('b'), riderAt('c')]]);

    $result = discovery($source)->discover(discoveryRequest(), new DateTimeImmutable());

    expect($result->eligibleCount())->toBe(3)
        ->and($result->searchRadiusMetres)->toBe(3_000)
        // One search. Widening after finding enough would cost provider calls
        // for riders who were never going to win.
        ->and($source->radiiSearched)->toBe([3_000.0]);
});

it('widens only as far as it must', function (): void {
    $source = sourceReturning([
        3_000 => [riderAt('a')],
        6_000 => [riderAt('a'), riderAt('b'), riderAt('c')],
    ]);

    $result = discovery($source)->discover(discoveryRequest(), new DateTimeImmutable());

    expect($source->radiiSearched)->toBe([3_000.0, 6_000.0])
        ->and($result->searchRadiusMetres)->toBe(6_000)
        ->and($result->eligibleCount())->toBe(3);
});

it('never searches past the ceiling, however empty the map', function (): void {
    $source = sourceReturning([]);

    $result = discovery($source, initial: 3_000, max: 12_000)->discover(discoveryRequest(), new DateTimeImmutable());

    expect(max($source->radiiSearched))->toBe(12_000.0)
        ->and($result->hasEligible())->toBeFalse()
        // The map was genuinely empty — a different problem from a fleet that
        // is all ineligible, and it should be reported as such.
        ->and($result->mapWasEmpty())->toBeTrue();
});

it('terminates when the expansion factor cannot widen the search', function (): void {
    // A factor of 1.0 is a misconfiguration that would otherwise loop.
    $source = sourceReturning([]);

    $result = discovery($source, factor: 1.0)->discover(discoveryRequest(), new DateTimeImmutable());

    expect($source->radiiSearched)->toBe([3_000.0])
        ->and($result->searchRadiusMetres)->toBe(3_000);
});

it('gives up after a bounded number of rings even with a tiny expansion factor', function (): void {
    $source = sourceReturning([]);

    discovery($source, initial: 1_000, max: 1_000_000, factor: 1.01)
        ->discover(discoveryRequest(), new DateTimeImmutable());

    // Bounded regardless of configuration. An unbounded search is how one
    // order occupies a worker indefinitely.
    expect(count($source->radiiSearched))->toBeLessThanOrEqual(10);
});

it('returns the fewer riders it found rather than nothing', function (): void {
    $source = sourceReturning([3_000 => [riderAt('a')]]);

    $result = discovery($source, minPool: 3, max: 3_000)->discover(discoveryRequest(), new DateTimeImmutable());

    // One eligible rider beats zero: the floor is a target to search towards,
    // not a requirement for dispatching at all.
    expect($result->eligibleCount())->toBe(1);
});

it('caps the pool so scoring cost stays bounded', function (): void {
    $many = [];

    for ($i = 0; $i < 40; $i++) {
        $many[] = riderAt('r-'.$i);
    }

    $result = discovery(sourceReturning([3_000 => $many]), maxPool: 25)
        ->discover(discoveryRequest(), new DateTimeImmutable());

    // The twenty-sixth nearest rider is not going to win, and each one past
    // the cap costs a routed ETA.
    expect($result->eligibleCount())->toBe(25);
});

/**
 * The cap must not thin the pool before the rules run, or an ineligible rider
 * that sorted earlier would displace an eligible one.
 */
it('applies the pool cap after eligibility, not before', function (): void {
    $candidates = [];

    for ($i = 0; $i < 30; $i++) {
        $candidates[] = riderAt('offline-'.$i, 'offline');
    }

    $candidates[] = riderAt('online-1');

    $result = discovery(sourceReturning([3_000 => $candidates]), maxPool: 5)
        ->discover(discoveryRequest(), new DateTimeImmutable());

    expect($result->eligibleCount())->toBe(1)
        ->and($result->eligible[0]->riderId)->toBe('online-1');
});

it('reports why the riders it found could not be used', function (): void {
    $source = sourceReturning([
        3_000 => [riderAt('a', 'offline'), riderAt('b', 'offline'), riderAt('c', 'suspended')],
    ]);

    $result = discovery($source, max: 3_000)->discover(discoveryRequest(), new DateTimeImmutable());

    expect($result->hasEligible())->toBeFalse()
        ->and($result->mapWasEmpty())->toBeFalse()
        ->and($result->rawCandidateCount)->toBe(3)
        ->and($result->dominantRejection())->toBe(RejectionReason::RiderUnavailable)
        ->and($result->rejectionBreakdown)->toBe([RejectionReason::RiderUnavailable->value => 3]);
});
