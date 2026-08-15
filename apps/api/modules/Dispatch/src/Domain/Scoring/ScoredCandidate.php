<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Domain\Scoring;

use EruoFood\Dispatch\Domain\Candidate\RiderCandidate;
use EruoFood\Dispatch\Domain\Vehicle\Vehicle;

/** A candidate with their score, their breakdown, and the vehicle they would use. */
final readonly class ScoredCandidate
{
    public function __construct(
        public RiderCandidate $candidate,
        public float $score,
        public ScoreBreakdown $breakdown,
        public ?Vehicle $vehicle,
        public ?int $routedEtaSeconds = null,
        public ?int $routedDistanceMetres = null,
    ) {
    }

    public function riderId(): string
    {
        return $this->candidate->riderId;
    }
}
