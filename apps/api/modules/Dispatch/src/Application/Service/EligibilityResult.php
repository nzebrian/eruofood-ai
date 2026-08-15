<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Application\Service;

use EruoFood\Dispatch\Domain\Candidate\RiderCandidate;
use EruoFood\Dispatch\Domain\Enum\RejectionReason;

/**
 * Who survived eligibility, and the accounting for everybody who did not.
 *
 * The breakdown is not diagnostics bolted on afterwards — it is the primary
 * output alongside the survivors, and it is what gets written to
 * `dispatch_attempts.rejection_breakdown`. Without it, a dispatch that finds
 * nobody is indistinguishable from a dispatch that was never going to.
 */
final readonly class EligibilityResult
{
    /**
     * @param list<RiderCandidate> $eligible
     * @param array<string, int> $rejectionBreakdown reason value => rider count
     * @param array<string, RejectionReason> $reasonsByRider
     */
    public function __construct(
        public array $eligible,
        public array $rejectionBreakdown,
        public array $reasonsByRider = [],
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

    public function rejectedCount(): int
    {
        return array_sum($this->rejectionBreakdown);
    }

    /** Why this particular rider was excluded, if they were. */
    public function reasonFor(string $riderId): ?RejectionReason
    {
        return $this->reasonsByRider[$riderId] ?? null;
    }

    /**
     * The reason that eliminated the most riders — what an alert should lead with.
     */
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
