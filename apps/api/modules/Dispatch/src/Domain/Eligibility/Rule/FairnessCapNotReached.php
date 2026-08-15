<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Domain\Eligibility\Rule;

use DateTimeImmutable;
use EruoFood\Dispatch\Domain\Candidate\RiderCandidate;
use EruoFood\Dispatch\Domain\Eligibility\EligibilityRule;
use EruoFood\Dispatch\Domain\Enum\RejectionReason;
use EruoFood\Dispatch\Domain\Request\DispatchRequest;

/**
 * A rider who has taken a long run of deliveries back-to-back sits out a round.
 *
 * The one place fairness touches *eligibility* rather than scoring, and it is
 * deliberately narrow. Fairness normally reorders candidates — a penalty on the
 * score — because a fairness rule that made riders undispatchable would leave
 * orders unassigned in a thin market to make a distribution look better.
 *
 * This is the exception, and it is bounded: only after `consecutive_assignment_cap`
 * deliveries in an unbroken run, and only for one round. It exists because the
 * scoring penalty alone cannot stop a genuinely dominant rider in a small area
 * from taking everything until they are exhausted.
 *
 * Disabled with fairness as a whole, so a market that does not want it can
 * switch it off in configuration.
 */
final readonly class FairnessCapNotReached implements EligibilityRule
{
    public function __construct(
        private bool $enabled,
        private int $consecutiveCap,
    ) {
    }

    public function key(): string
    {
        return 'fairness_cap';
    }

    public function isMandatory(): bool
    {
        return false;
    }

    public function evaluate(RiderCandidate $candidate, DispatchRequest $request, DateTimeImmutable $now): ?RejectionReason
    {
        if (! $this->enabled || $this->consecutiveCap <= 0) {
            return null;
        }

        return $candidate->consecutiveAssignmentCount >= $this->consecutiveCap
            ? RejectionReason::FairnessCapReached
            : null;
    }
}
