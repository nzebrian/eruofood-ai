<?php

declare(strict_types=1);

use EruoFood\Dispatch\Application\Port\RiderPerformanceQuery;
use EruoFood\Dispatch\Application\Service\ScoringService;
use EruoFood\Dispatch\Domain\Candidate\RiderCandidate;
use EruoFood\Dispatch\Domain\Enum\VehicleType;
use EruoFood\Dispatch\Domain\Request\DispatchRequest;
use EruoFood\Dispatch\Domain\Scoring\FairnessPolicy;
use EruoFood\Dispatch\Domain\Scoring\ScoredCandidate;
use EruoFood\Dispatch\Domain\Vehicle\Vehicle;
use EruoFood\Geo\Contracts\DeliveryDistance;
use EruoFood\Geo\Contracts\DeliveryDistanceProvider;

/**
 * M26 — ranking the eligible riders, and being able to say why.
 *
 * The properties that matter here are less about the exact numbers (those are
 * configuration) and more about the shape:
 *
 * - **fairness reorders, it never vetoes** — a bounded multiplier, so a
 *   genuinely much closer rider still wins;
 * - **a provider outage degrades scoring, it does not stop dispatch** — the ETA
 *   factor drops out and its weight is redistributed, rather than scoring zero
 *   and punishing every rider for Google's bad day;
 * - **missing data scores neutral, not zero** — otherwise a new rider can never
 *   get the work that would give them a rating;
 * - **every score can be explained** — because "why do I never get the airport
 *   runs?" deserves an answer.
 */
const SCORE_NOW = '2026-06-01 12:00:00';

function performanceReturning(array $byRider): RiderPerformanceQuery
{
    return new class ($byRider) implements RiderPerformanceQuery {
        public function __construct(private array $byRider)
        {
        }

        public function forRiders(array $riderIds): array
        {
            return array_intersect_key($this->byRider, array_flip($riderIds));
        }
    };
}

function distanceProviderReturning(?int $seconds, ?int $metres = 4_000): DeliveryDistanceProvider
{
    return new class ($seconds, $metres) implements DeliveryDistanceProvider {
        public int $calls = 0;

        public function __construct(private ?int $seconds, private ?int $metres)
        {
        }

        public function between(
            float $originLat,
            float $originLng,
            float $destLat,
            float $destLng,
            ?string $travelMode = null,
        ): ?DeliveryDistance {
            $this->calls++;

            if ($this->seconds === null) {
                return null;
            }

            return new DeliveryDistance(
                distanceMetres: (int) $this->metres,
                durationSeconds: $this->seconds,
                source: 'test',
                isBillable: false,
                ageSeconds: 0,
                durationInTrafficSeconds: null,
            );
        }

        public function routedPricingEnabled(): bool
        {
            return false;
        }
    };
}

function approvedVehicle(VehicleType $type = VehicleType::Bike): Vehicle
{
    $at = new DateTimeImmutable('2026-01-01 09:00:00');

    $vehicle = Vehicle::register(
        id: 'veh-'.$type->value.'-'.mt_rand(),
        riderId: 'rider',
        type: $type,
        now: $at,
        registrationNumber: $type->requiresRegistration() ? 'LAG-'.mt_rand(100, 999) : null,
    );

    $vehicle->approve('operator', $at);

    return $vehicle;
}

function scoreCandidate(array $overrides = []): RiderCandidate
{
    $defaults = [
        'riderId' => 'rider-1',
        'userId' => 'user-1',
        'riderStatus' => 'online',
        'latitude' => 6.5,
        'longitude' => 3.3,
        'straightLineDistanceMetres' => 1_000.0,
        'locationRecordedAt' => new DateTimeImmutable(SCORE_NOW),
        'locationAccuracyMetres' => 20.0,
        'vehicles' => [approvedVehicle()],
        'activeDeliveryCount' => 0,
        'observedAt' => new DateTimeImmutable(SCORE_NOW),
        'allVehicles' => [],
        'lastAssignedAt' => null,
        'recentAssignmentCount' => 0,
        'consecutiveAssignmentCount' => 0,
    ];

    return new RiderCandidate(...array_merge($defaults, $overrides));
}

function scoreRequest(VehicleType $required = VehicleType::Bike, ?string $zoneId = null): DispatchRequest
{
    return DispatchRequest::open(
        id: 'req-1',
        deliveryId: 'del-1',
        orderId: 'ord-1',
        vendorId: 'ven-1',
        pickupLat: 6.5,
        pickupLng: 3.3,
        dropoffLat: 6.4,
        dropoffLng: 3.4,
        now: new DateTimeImmutable(SCORE_NOW),
        maxAttempts: 5,
        timeBudgetSeconds: 600,
        requiredVehicleType: $required,
        zoneId: $zoneId,
    );
}

function scorer(
    ?DeliveryDistanceProvider $distances = null,
    array $performance = [],
    ?FairnessPolicy $fairness = null,
    array $weights = [
        'proximity' => 0.30,
        'eta' => 0.25,
        'vehicle_suitability' => 0.15,
        'workload' => 0.10,
        'performance' => 0.10,
        'acceptance_rate' => 0.05,
        'zone_affinity' => 0.05,
    ],
): ScoringService {
    return new ScoringService(
        performanceReturning($performance),
        $fairness ?? new FairnessPolicy(false, 0.30, 0.08, 1_800, 0.10),
        $distances,
        $weights,
        15_000,
        3_600,
        3,
    );
}

it('ranks the nearer rider first, all else equal', function (): void {
    $ranked = scorer()->rank([
        scoreCandidate(['riderId' => 'far', 'straightLineDistanceMetres' => 9_000.0]),
        scoreCandidate(['riderId' => 'near', 'straightLineDistanceMetres' => 500.0]),
    ], scoreRequest(), new DateTimeImmutable(SCORE_NOW));

    expect(array_map(static fn (ScoredCandidate $c): string => $c->riderId(), $ranked))
        ->toBe(['near', 'far']);
});

it('prefers a rider carrying nothing over one already busy', function (): void {
    $ranked = scorer()->rank([
        scoreCandidate(['riderId' => 'busy', 'activeDeliveryCount' => 2]),
        scoreCandidate(['riderId' => 'free', 'activeDeliveryCount' => 0]),
    ], scoreRequest(), new DateTimeImmutable(SCORE_NOW));

    expect($ranked[0]->riderId())->toBe('free');
});

it('prefers the smallest sufficient vehicle over a needlessly large one', function (): void {
    // A bus can carry what a bike can. It is also slower through Lagos traffic
    // and more expensive to run, so it should not win a bike-sized job.
    $ranked = scorer()->rank([
        scoreCandidate(['riderId' => 'bus', 'vehicles' => [approvedVehicle(VehicleType::Bus)]]),
        scoreCandidate(['riderId' => 'bike', 'vehicles' => [approvedVehicle(VehicleType::Bike)]]),
    ], scoreRequest(VehicleType::Bike), new DateTimeImmutable(SCORE_NOW));

    expect($ranked[0]->riderId())->toBe('bike')
        ->and($ranked[0]->vehicle?->type())->toBe(VehicleType::Bike);
});

/**
 * The outage behaviour. Refusing to dispatch because a map provider is down
 * would turn a supplier's bad day into the platform's.
 */
it('drops the ETA factor when routing is unavailable, and still ranks', function (): void {
    $provider = distanceProviderReturning(null);

    $ranked = scorer($provider)->rank([
        scoreCandidate(['riderId' => 'a', 'straightLineDistanceMetres' => 4_000.0]),
        scoreCandidate(['riderId' => 'b', 'straightLineDistanceMetres' => 800.0]),
    ], scoreRequest(), new DateTimeImmutable(SCORE_NOW));

    expect($ranked[0]->riderId())->toBe('b')
        ->and($ranked[0]->breakdown->factors)->not->toHaveKey('eta')
        ->and($ranked[0]->routedEtaSeconds)->toBeNull()
        // The surviving weights are renormalised, so the remaining factors mean
        // the same relative to each other as they did before.
        ->and(round(array_sum($ranked[0]->breakdown->weights), 6))->toBe(1.0);
});

it('uses the routed ETA when M25 can supply one', function (): void {
    $ranked = scorer(distanceProviderReturning(300))->rank(
        [scoreCandidate()],
        scoreRequest(),
        new DateTimeImmutable(SCORE_NOW),
    );

    expect($ranked[0]->routedEtaSeconds)->toBe(300)
        ->and($ranked[0]->routedDistanceMetres)->toBe(4_000)
        ->and($ranked[0]->breakdown->factors)->toHaveKey('eta');
});

it('routes a bike and a car through different travel modes', function (): void {
    $modes = [];

    $provider = new class ($modes) implements DeliveryDistanceProvider {
        /** @var list<string|null> */
        public array $modes = [];

        public function __construct(array $ignored)
        {
        }

        public function between(float $a, float $b, float $c, float $d, ?string $travelMode = null): ?DeliveryDistance
        {
            $this->modes[] = $travelMode;

            return null;
        }

        public function routedPricingEnabled(): bool
        {
            return false;
        }
    };

    scorer($provider)->rank([
        scoreCandidate(['riderId' => 'bike', 'vehicles' => [approvedVehicle(VehicleType::Bike)]]),
        scoreCandidate(['riderId' => 'car', 'vehicles' => [approvedVehicle(VehicleType::Car)]]),
    ], scoreRequest(), new DateTimeImmutable(SCORE_NOW));

    // A two-wheeler does not take the same route through Lagos as a car, and
    // pretending otherwise gives an ETA that is wrong for one of them.
    expect($provider->modes)->toBe(['two_wheeler', 'driving']);
});

/**
 * A rider with no history must not be scored as though they had a bad one.
 */
it('scores a rider with no rating neutrally rather than zero', function (): void {
    $withRating = scorer(performance: ['rated' => ['rating' => 5.0, 'acceptance_rate' => 1.0, 'completion_rate' => 1.0, 'deliveries' => 90]])
        ->rank([scoreCandidate(['riderId' => 'rated'])], scoreRequest(), new DateTimeImmutable(SCORE_NOW));

    $withoutRating = scorer()
        ->rank([scoreCandidate(['riderId' => 'new'])], scoreRequest(), new DateTimeImmutable(SCORE_NOW));

    expect($withoutRating[0]->breakdown->factors['performance'])->toBe(0.5)
        // Lower than a five-star rider — that is fair — but nowhere near zero,
        // which would make a first delivery unreachable.
        ->and($withoutRating[0]->breakdown->factors['performance'])
        ->toBeLessThan($withRating[0]->breakdown->factors['performance'])
        ->and($withoutRating[0]->score)->toBeGreaterThan(0.0);
});

it('explains every score it produces', function (): void {
    $ranked = scorer(distanceProviderReturning(600))
        ->rank([scoreCandidate()], scoreRequest(), new DateTimeImmutable(SCORE_NOW));

    $breakdown = $ranked[0]->breakdown;

    expect(array_keys($breakdown->factors))->toContain('proximity', 'eta', 'vehicle_suitability', 'workload')
        ->and($breakdown->finalScore)->toBe($ranked[0]->score)
        ->and($breakdown->dominantFactor())->not->toBeNull()
        ->and($breakdown->toArray())->toHaveKeys(['factors', 'weights', 'base_score', 'fairness_multiplier', 'final_score']);
});

it('reports contribution rather than raw factor score, which is what a person wants', function (): void {
    $ranked = scorer()->rank([scoreCandidate()], scoreRequest(), new DateTimeImmutable(SCORE_NOW));
    $breakdown = $ranked[0]->breakdown;

    // A factor scoring 1.0 at weight 0.05 matters less than one scoring 0.4 at
    // weight 0.30, and the raw scores alone hide that.
    foreach ($breakdown->contributions() as $name => $contribution) {
        expect($contribution)->toBe($breakdown->factors[$name] * $breakdown->weights[$name]);
    }
});

it('falls back to equal weighting rather than breaking when every weight is zero', function (): void {
    $ranked = scorer(weights: [])->rank([
        scoreCandidate(['riderId' => 'far', 'straightLineDistanceMetres' => 12_000.0]),
        scoreCandidate(['riderId' => 'near', 'straightLineDistanceMetres' => 200.0]),
    ], scoreRequest(), new DateTimeImmutable(SCORE_NOW));

    // "No preferences" should still produce a sane ordering, not a crash and
    // not a coin toss.
    expect($ranked[0]->riderId())->toBe('near')
        ->and(round(array_sum($ranked[0]->breakdown->weights), 6))->toBe(1.0);
});

it('keeps every score inside 0 and 1', function (): void {
    $ranked = scorer(distanceProviderReturning(30))->rank([
        scoreCandidate(['straightLineDistanceMetres' => 0.0]),
        scoreCandidate(['riderId' => 'r2', 'straightLineDistanceMetres' => 999_999.0, 'activeDeliveryCount' => 99]),
    ], scoreRequest(), new DateTimeImmutable(SCORE_NOW));

    foreach ($ranked as $candidate) {
        expect($candidate->breakdown->baseScore)->toBeGreaterThanOrEqual(0.0)
            ->and($candidate->breakdown->baseScore)->toBeLessThanOrEqual(1.0);
    }
});

/*
|------------------------------------------------------------------------------
| Fairness — bounded, and that bound is the whole design.
|------------------------------------------------------------------------------
*/

it('leaves scores untouched when fairness is disabled', function (): void {
    $policy = new FairnessPolicy(false, 0.30, 0.08, 1_800, 0.10);

    expect($policy->multiplierFor(scoreCandidate(['recentAssignmentCount' => 20]), new DateTimeImmutable(SCORE_NOW)))
        ->toBe(1.0);
});

it('penalises a rider who has had a lot of recent work', function (): void {
    $policy = new FairnessPolicy(true, 0.30, 0.08, 1_800, 0.10);
    $now = new DateTimeImmutable(SCORE_NOW);

    $busy = $policy->multiplierFor(
        scoreCandidate(['recentAssignmentCount' => 3, 'lastAssignedAt' => $now]),
        $now,
    );

    expect(round($busy, 4))->toBe(0.76);
});

it('never penalises past the configured ceiling', function (): void {
    $policy = new FairnessPolicy(true, 0.30, 0.08, 1_800, 0.10);
    $now = new DateTimeImmutable(SCORE_NOW);

    $multiplier = $policy->multiplierFor(
        scoreCandidate(['recentAssignmentCount' => 500, 'lastAssignedAt' => $now]),
        $now,
    );

    // 0.70, not 0.0. A fairness system that could zero a score would be an
    // eligibility rule wearing a multiplier's clothes.
    expect(round($multiplier, 4))->toBe(0.70)
        ->and($multiplier)->toBeGreaterThan(0.0);
});

it('boosts a rider nobody has offered anything for a while', function (): void {
    $policy = new FairnessPolicy(true, 0.30, 0.08, 1_800, 0.10);
    $now = new DateTimeImmutable(SCORE_NOW);

    $idle = scoreCandidate(['lastAssignedAt' => new DateTimeImmutable('2026-06-01 11:00:00')]);
    $justWorked = scoreCandidate(['lastAssignedAt' => new DateTimeImmutable('2026-06-01 11:59:00')]);

    // Without the boost a rider slightly further from every restaurant is never
    // quite closest, never gets work, and stops turning on the app.
    expect(round($policy->multiplierFor($idle, $now), 4))->toBe(1.10)
        ->and(round($policy->multiplierFor($justWorked, $now), 4))->toBe(1.0);
});

it('boosts a rider who has never been assigned anything, so they can start', function (): void {
    $policy = new FairnessPolicy(true, 0.30, 0.08, 1_800, 0.10);

    expect(round($policy->multiplierFor(scoreCandidate(['lastAssignedAt' => null]), new DateTimeImmutable(SCORE_NOW)), 4))
        ->toBe(1.10);
});

/**
 * The property the whole fairness design is bounded for.
 */
it('cannot send a delivery across the city in the name of fairness', function (): void {
    $now = new DateTimeImmutable(SCORE_NOW);

    // Built through the bounded factory, exactly as the service provider does.
    // Constructed directly with these numbers it *could*: a 0.30 penalty plus a
    // 0.10 boost swings further than proximity's 0.30 weight is worth, and an
    // earlier draft did send the twelve-kilometre rider. The clamp is what
    // makes the promise structural.
    $fairness = FairnessPolicy::boundedBy(0.30, true, 0.30, 0.08, 1_800, 0.10);

    $ranked = scorer(fairness: $fairness)->rank([
        // Five hundred metres away, but has been working hard.
        scoreCandidate([
            'riderId' => 'near-busy',
            'straightLineDistanceMetres' => 500.0,
            'recentAssignmentCount' => 10,
            'lastAssignedAt' => $now,
        ]),
        // Twelve kilometres away and idle all morning.
        scoreCandidate([
            'riderId' => 'far-idle',
            'straightLineDistanceMetres' => 12_000.0,
            'lastAssignedAt' => new DateTimeImmutable('2026-06-01 08:00:00'),
        ]),
    ], scoreRequest(), $now);

    expect($ranked[0]->riderId())->toBe('near-busy');
});

it('does let fairness decide between two riders of similar distance', function (): void {
    $now = new DateTimeImmutable(SCORE_NOW);
    $fairness = FairnessPolicy::boundedBy(0.30, true, 0.30, 0.08, 1_800, 0.10);

    $ranked = scorer(fairness: $fairness)->rank([
        scoreCandidate([
            'riderId' => 'busy',
            'straightLineDistanceMetres' => 1_000.0,
            'recentAssignmentCount' => 4,
            'lastAssignedAt' => $now,
        ]),
        scoreCandidate([
            'riderId' => 'quiet',
            'straightLineDistanceMetres' => 1_100.0,
            'lastAssignedAt' => new DateTimeImmutable('2026-06-01 09:00:00'),
        ]),
    ], scoreRequest(), $now);

    // Efficiency takes the extremes; fairness decides the middle.
    expect($ranked[0]->riderId())->toBe('quiet');
});

it('keeps the base score visible even after fairness is applied', function (): void {
    $now = new DateTimeImmutable(SCORE_NOW);

    $ranked = scorer(fairness: new FairnessPolicy(true, 0.30, 0.08, 1_800, 0.10))
        ->rank([scoreCandidate(['recentAssignmentCount' => 2, 'lastAssignedAt' => $now])], scoreRequest(), $now);

    $breakdown = $ranked[0]->breakdown;

    expect($breakdown->fairnessMultiplier)->toBeLessThan(1.0)
        ->and($breakdown->baseScore)->toBeGreaterThan($breakdown->finalScore)
        // Recorded separately so a rider asking "why did I not get that one?"
        // can be told which half of the answer was fairness.
        ->and(round($breakdown->baseScore * $breakdown->fairnessMultiplier, 10))
        ->toBe(round($breakdown->finalScore, 10));
});

/**
 * The invariant itself, independent of any particular pair of riders.
 *
 * If fairness could swing further than proximity is worth, it could overturn
 * *any* distance gap, and the bound would be decoration.
 */
it('never lets the fairness swing exceed what proximity is worth', function (): void {
    $config = require __DIR__.'/../../../../config/dispatch.php';
    $proximityWeight = (float) $config['scoring']['weights']['proximity'];

    $policy = FairnessPolicy::boundedBy(
        $proximityWeight,
        true,
        (float) $config['fairness']['max_penalty'],
        (float) $config['fairness']['penalty_per_recent_assignment'],
        (int) $config['fairness']['idle_boost_after_seconds'],
        (float) $config['fairness']['idle_boost'],
    );

    expect($policy->maxSwing())->toBeLessThanOrEqual($proximityWeight + 1e-9);
});

it('scales an over-generous fairness configuration down rather than refusing to start', function (): void {
    // An operator setting a 0.9 penalty should get a clamped policy and a
    // working platform, not a boot failure at 2am.
    $policy = FairnessPolicy::boundedBy(0.30, true, 0.90, 0.30, 1_800, 0.30);

    expect(round($policy->maxSwing(), 6))->toBe(0.30);

    $now = new DateTimeImmutable(SCORE_NOW);

    // And the relative sizes an operator chose are preserved through the clamp:
    // three parts penalty to one part boost, before and after.
    $idle = $policy->multiplierFor(scoreCandidate(['lastAssignedAt' => new DateTimeImmutable('2026-06-01 09:00:00')]), $now);
    $hammered = $policy->multiplierFor(scoreCandidate(['recentAssignmentCount' => 99, 'lastAssignedAt' => $now]), $now);

    expect(round($idle - 1.0, 6))->toBe(0.075)
        ->and(round(1.0 - $hammered, 6))->toBe(0.225);
});

it('leaves a configuration already inside the bound untouched', function (): void {
    $policy = FairnessPolicy::boundedBy(0.30, true, 0.15, 0.08, 1_800, 0.05);

    expect(round($policy->maxSwing(), 6))->toBe(0.20);
});
