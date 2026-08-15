<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Application\Service;

use DateTimeImmutable;
use EruoFood\Dispatch\Application\Port\RiderPerformanceQuery;
use EruoFood\Dispatch\Domain\Candidate\RiderCandidate;
use EruoFood\Dispatch\Domain\Request\DispatchRequest;
use EruoFood\Dispatch\Domain\Scoring\FairnessPolicy;
use EruoFood\Dispatch\Domain\Scoring\ScoreBreakdown;
use EruoFood\Dispatch\Domain\Scoring\ScoredCandidate;
use EruoFood\Geo\Contracts\DeliveryDistanceProvider;

/**
 * Rank the eligible riders, and be able to say why.
 *
 * ## Seven factors, each normalised to 0–1
 *
 * Distance, ETA, vehicle suitability, workload, performance, acceptance rate
 * and zone affinity, combined by weights read from configuration. Nothing here
 * decides *whether* a rider may take the job — that was settled by eligibility,
 * which runs first precisely so no ineligible rider costs a routing call.
 *
 * ## The ETA is the expensive factor, and it is optional
 *
 * A routed ETA is a paid provider call per candidate. It is genuinely better
 * than straight-line distance — Lagos road distance runs 1.3 to 1.6 times the
 * crow flight, and unevenly — so it is used when M25 can supply it. When it
 * cannot, the ETA factor is dropped and its weight is redistributed across the
 * remaining factors rather than scored as zero. Scoring a missing measurement
 * as zero would punish every rider equally for a provider outage and quietly
 * change what the other weights mean.
 *
 * ## Fairness multiplies, it does not veto
 *
 * The multiplier is applied last and recorded separately, so the base score
 * stays visible in the breakdown. See {@see FairnessPolicy} for why it is
 * bounded.
 */
final readonly class ScoringService
{
    /**
     * @param array<string, float> $weights
     */
    public function __construct(
        private RiderPerformanceQuery $performance,
        private FairnessPolicy $fairness,
        private ?DeliveryDistanceProvider $distances,
        private array $weights,
        private int $maxDistanceMetres,
        private int $maxEtaSeconds,
        private int $maxActiveDeliveries,
    ) {
    }

    /**
     * @param list<RiderCandidate> $candidates
     * @return list<ScoredCandidate> best first
     */
    public function rank(array $candidates, DispatchRequest $request, DateTimeImmutable $now): array
    {
        if ($candidates === []) {
            return [];
        }

        $performance = $this->performance->forRiders(array_map(
            static fn (RiderCandidate $c): string => $c->riderId,
            $candidates,
        ));

        $scored = [];

        foreach ($candidates as $candidate) {
            $scored[] = $this->score($candidate, $request, $performance[$candidate->riderId] ?? [], $now);
        }

        usort($scored, static fn (ScoredCandidate $a, ScoredCandidate $b): int => $b->score <=> $a->score);

        return $scored;
    }

    /**
     * @param array<string, mixed> $performance
     */
    private function score(
        RiderCandidate $candidate,
        DispatchRequest $request,
        array $performance,
        DateTimeImmutable $now,
    ): ScoredCandidate {
        $vehicle = $candidate->bestVehicleFor(
            $request->requiredVehicleType(),
            $request->loadKg(),
            $request->loadLitres(),
        );

        $routed = $this->routedLeg($candidate, $request, $vehicle?->type()->travelMode());

        $factors = [
            'proximity' => $this->normaliseDescending($candidate->straightLineDistanceMetres, $this->maxDistanceMetres),
            'vehicle_suitability' => $this->vehicleSuitability($candidate, $request),
            'workload' => $this->normaliseDescending((float) $candidate->activeDeliveryCount, max(1, $this->maxActiveDeliveries)),
            'performance' => $this->neutralOr($performance['rating'] ?? null, static fn (float $r): float => min(1.0, max(0.0, $r / 5.0))),
            'acceptance_rate' => $this->neutralOr($performance['acceptance_rate'] ?? null, static fn (float $r): float => min(1.0, max(0.0, $r))),
            'zone_affinity' => $this->zoneAffinity($candidate, $request),
        ];

        if ($routed !== null) {
            $factors['eta'] = $this->normaliseDescending((float) $routed['eta_seconds'], $this->maxEtaSeconds);
        }

        $weights = $this->weightsFor(array_keys($factors));
        $base = $this->combine($factors, $weights);
        $multiplier = $this->fairness->multiplierFor($candidate, $now);

        return new ScoredCandidate(
            candidate: $candidate,
            score: $base * $multiplier,
            breakdown: new ScoreBreakdown($factors, $weights, $base, $multiplier, $base * $multiplier),
            vehicle: $vehicle,
            routedEtaSeconds: $routed['eta_seconds'] ?? null,
            routedDistanceMetres: $routed['distance_metres'] ?? null,
        );
    }

    /**
     * Ask M25 for the road leg from the rider to the pickup.
     *
     * Null on any failure, and that is deliberate: a provider outage must
     * degrade scoring, not stop dispatch. The alternative — refusing to
     * dispatch because Google is down — turns a supplier's bad day into the
     * platform's.
     *
     * Note this is the rider→pickup leg, not the billable customer distance.
     * M25 owns what customers are charged and M26 never touches it.
     *
     * @return array{eta_seconds: int, distance_metres: int}|null
     */
    private function routedLeg(RiderCandidate $candidate, DispatchRequest $request, ?string $travelMode): ?array
    {
        if ($this->distances === null) {
            return null;
        }

        $leg = $this->distances->between(
            $candidate->latitude,
            $candidate->longitude,
            $request->pickupLat(),
            $request->pickupLng(),
            $travelMode,
        );

        if ($leg === null) {
            return null;
        }

        return [
            // In traffic when the provider knows it: a bike and a bus reach the
            // same restaurant at very different times in Lagos, and the free
            // -flow duration hides exactly that.
            'eta_seconds' => $leg->durationInTrafficSeconds ?? $leg->durationSeconds,
            'distance_metres' => $leg->distanceMetres,
        ];
    }

    /**
     * How well the vehicle fits, rather than merely whether it is allowed.
     *
     * Eligibility already refused anything that cannot do the job. This
     * prefers the *right* vehicle among the acceptable ones: an exact match
     * scores highest, and a needlessly larger vehicle scores lower with a small
     * credit for traffic agility, because a bike genuinely beats a bus through
     * congestion.
     */
    private function vehicleSuitability(RiderCandidate $candidate, DispatchRequest $request): float
    {
        $vehicle = $candidate->bestVehicleFor(
            $request->requiredVehicleType(),
            $request->loadKg(),
            $request->loadLitres(),
        );

        if ($vehicle === null) {
            return 0.0;
        }

        $overshoot = $vehicle->type()->capacityRank() - $request->requiredVehicleType()->capacityRank();
        $score = max(0.2, 1.0 - ($overshoot * 0.2));

        return min(1.0, $vehicle->type()->isTrafficAgile() ? $score + 0.1 : $score);
    }

    /**
     * A small preference for riders already working the delivery's zone.
     *
     * Local knowledge is real — which gate, which back street, which building
     * has no number. The weight is deliberately the smallest of the seven,
     * because over-weighting it would pin riders to one area.
     */
    private function zoneAffinity(RiderCandidate $candidate, DispatchRequest $request): float
    {
        if ($request->zoneId() === null) {
            // Nothing to be affine to. Neutral rather than zero, so an unzoned
            // delivery does not silently penalise every candidate equally.
            return 0.5;
        }

        // Proximity to the pickup is the proxy available in M26; a proper
        // "zones this rider usually works" signal needs the history that M29's
        // performance engine will own.
        return $this->normaliseDescending($candidate->straightLineDistanceMetres, $this->maxDistanceMetres);
    }

    /** 1.0 at zero, falling linearly to 0.0 at the bound and never below it. */
    private function normaliseDescending(float $value, float $bound): float
    {
        if ($bound <= 0.0) {
            return 0.0;
        }

        return max(0.0, min(1.0, 1.0 - ($value / $bound)));
    }

    /**
     * Absent data scores neutral, not zero.
     *
     * A rider with no deliveries yet has no rating. Scoring that as zero would
     * make it impossible for a new rider ever to get the work that would give
     * them a rating.
     *
     * @param callable(float): float $normalise
     */
    private function neutralOr(mixed $value, callable $normalise): float
    {
        return is_numeric($value) ? $normalise((float) $value) : 0.5;
    }

    /**
     * The weights for the factors actually present, renormalised to sum to 1.
     *
     * Renormalising is what lets the ETA factor be dropped during a provider
     * outage without changing what the other factors mean relative to each
     * other.
     *
     * @param list<string> $factorNames
     * @return array<string, float>
     */
    private function weightsFor(array $factorNames): array
    {
        $weights = [];

        foreach ($factorNames as $name) {
            $weights[$name] = max(0.0, (float) ($this->weights[$name] ?? 0.0));
        }

        $total = array_sum($weights);

        if ($total <= 0.0) {
            // Every weight zeroed in configuration. Equal weighting is the only
            // honest reading of "no preferences", and it keeps dispatch working.
            $equal = 1.0 / max(1, count($factorNames));

            return array_map(static fn (): float => $equal, $weights);
        }

        return array_map(static fn (float $w): float => $w / $total, $weights);
    }

    /**
     * @param array<string, float> $factors
     * @param array<string, float> $weights
     */
    private function combine(array $factors, array $weights): float
    {
        $score = 0.0;

        foreach ($factors as $name => $value) {
            $score += $value * ($weights[$name] ?? 0.0);
        }

        return min(1.0, max(0.0, $score));
    }
}
