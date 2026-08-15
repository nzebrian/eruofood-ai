<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Application\Service;

use EruoFood\Dispatch\Domain\Candidate\RiderCandidate;
use EruoFood\Dispatch\Domain\Enum\RejectionReason;

/**
 * What one round of searching found, and how hard it had to look.
 *
 * The radius and the raw count travel with the result because they are what
 * `dispatch_attempts` records, and together with the rejection breakdown they
 * are what separates "the map was empty" from "the map was full of riders who
 * could not be used".
 */
final readonly class CandidateDiscovery
{
    /**
     * @param list<RiderCandidate> $eligible
     * @param array<string, int> $rejectionBreakdown
     */
    public function __construct(
        public array $eligible,
        public array $rejectionBreakdown,
        public int $rawCandidateCount,
        public int $searchRadiusMetres,
    ) {
    }

    public function hasEligible(): bool
    {
        return $this->eligible !== [];
    }

    public function eligibleCount(): int
    {
        return count($this->eligible);
    }

    /** Nobody's position was anywhere near — a different problem from an ineligible fleet. */
    public function mapWasEmpty(): bool
    {
        return $this->rawCandidateCount === 0;
    }

    public function dominantRejection(): ?RejectionReason
    {
        if ($this->rejectionBreakdown === []) {
            return null;
        }

        $sorted = $this->rejectionBreakdown;
        arsort($sorted);

        return RejectionReason::tryFrom((string) array_key_first($sorted));
    }
}
