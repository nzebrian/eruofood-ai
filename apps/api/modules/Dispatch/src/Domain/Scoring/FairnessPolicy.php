<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Domain\Scoring;

use DateTimeImmutable;
use EruoFood\Dispatch\Domain\Candidate\RiderCandidate;

/**
 * Stop the nearest high performer taking every order in their area.
 *
 * Without something here, dispatch has a runaway loop built into it: the rider
 * who scores highest wins, earns more, gets a better rating, scores higher
 * still. That starves everybody else in the zone and eventually burns out the
 * person winning it — which is worse for the platform than the small efficiency
 * loss of spreading work out.
 *
 * ## Bounded, and that bound is structural
 *
 * Fairness is a **multiplier on the score**, never an eligibility rule (with
 * one narrow exception documented in `FairnessCapNotReached`). It reorders
 * candidates; it can never make a rider undispatchable, and it must never send
 * a delivery to somebody twelve kilometres away when one is five hundred metres
 * away.
 *
 * That last promise is not something to hope the numbers deliver. The total
 * swing fairness can apply — `maxPenalty + idleBoost` — is clamped by
 * {@see boundedBy()} to no more than the weight proximity carries in the score.
 * If fairness could swing further than distance is worth, it could overturn any
 * distance gap at all, and the promise above would be decoration.
 *
 * It was: an earlier draft paired a 0.30 penalty and a 0.10 boost with a 0.30
 * proximity weight, and a rider twelve kilometres away beat one five hundred
 * metres away. The test that caught it is
 * `it cannot send a delivery across the city in the name of fairness`.
 *
 * A fairness system that could override distance entirely would deliver cold
 * food in the name of equity, which serves nobody — least of all the rider who
 * gets the twelve-kilometre run.
 */
final readonly class FairnessPolicy
{
    public function __construct(
        private bool $enabled,
        private float $maxPenalty,
        private float $penaltyPerRecentAssignment,
        private int $idleBoostAfterSeconds,
        private float $idleBoost,
    ) {
    }

    /**
     * Build a policy whose total swing cannot exceed what proximity is worth.
     *
     * The configured penalty and boost are scaled down together if they would
     * between them swing further than `$proximityWeight`, preserving their
     * ratio so an operator's intent about their relative size survives.
     *
     * This is the invariant that makes the promise in the class docblock true
     * by construction, rather than true for the current numbers.
     */
    public static function boundedBy(
        float $proximityWeight,
        bool $enabled,
        float $maxPenalty,
        float $penaltyPerRecentAssignment,
        int $idleBoostAfterSeconds,
        float $idleBoost,
    ): self {
        $penalty = max(0.0, $maxPenalty);
        $boost = max(0.0, $idleBoost);
        $swing = $penalty + $boost;
        $ceiling = max(0.0, $proximityWeight);

        if ($swing > $ceiling && $swing > 0.0) {
            $scale = $ceiling / $swing;
            $penalty *= $scale;
            $boost *= $scale;
            $penaltyPerRecentAssignment = max(0.0, $penaltyPerRecentAssignment) * $scale;
        }

        return new self($enabled, $penalty, $penaltyPerRecentAssignment, $idleBoostAfterSeconds, $boost);
    }

    /** The most this policy can move a score in either direction, for assertions and reporting. */
    public function maxSwing(): float
    {
        return $this->enabled ? max(0.0, $this->maxPenalty) + max(0.0, $this->idleBoost) : 0.0;
    }

    /**
     * The multiplier to apply to a candidate's base score.
     *
     * Always in `[1 - maxPenalty, 1 + idleBoost]`. Never zero, never negative:
     * either would be an eligibility decision wearing a multiplier's clothes.
     */
    public function multiplierFor(RiderCandidate $candidate, DateTimeImmutable $now): float
    {
        if (! $this->enabled) {
            return 1.0;
        }

        $penalty = min(
            max(0.0, $this->maxPenalty),
            max(0, $candidate->recentAssignmentCount) * max(0.0, $this->penaltyPerRecentAssignment),
        );

        $boost = $this->idleBoostFor($candidate, $now);

        return max(0.0, 1.0 - $penalty + $boost);
    }

    /**
     * A rider nobody has offered anything for a while gets a nudge.
     *
     * Without it a quiet corner of the map becomes a dead one: a rider who
     * happens to be slightly further from every restaurant is never quite
     * closest, never gets work, and eventually stops turning on the app. A
     * rider who has never been assigned at all — a new joiner — gets the boost
     * too, which is the only thing that gets them their first delivery.
     */
    private function idleBoostFor(RiderCandidate $candidate, DateTimeImmutable $now): float
    {
        if ($this->idleBoostAfterSeconds <= 0) {
            return 0.0;
        }

        $idleSeconds = $candidate->idleSeconds($now);

        if ($idleSeconds === null) {
            return max(0.0, $this->idleBoost);
        }

        return $idleSeconds >= $this->idleBoostAfterSeconds ? max(0.0, $this->idleBoost) : 0.0;
    }
}
