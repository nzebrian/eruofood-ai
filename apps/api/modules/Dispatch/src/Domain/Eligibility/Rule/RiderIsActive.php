<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Domain\Eligibility\Rule;

use DateTimeImmutable;
use EruoFood\Dispatch\Domain\Candidate\RiderCandidate;
use EruoFood\Dispatch\Domain\Eligibility\EligibilityRule;
use EruoFood\Dispatch\Domain\Enum\RejectionReason;
use EruoFood\Dispatch\Domain\Request\DispatchRequest;

/**
 * A suspended rider receives no work.
 *
 * Separate from availability: a rider who is offline has simply finished for
 * the day, while a suspended one is under a decision somebody made. Collapsing
 * the two would make a suspension look like a shift ending in every report.
 */
final class RiderIsActive implements EligibilityRule
{
    public function key(): string
    {
        return 'rider_active';
    }

    public function isMandatory(): bool
    {
        return false;
    }

    public function evaluate(RiderCandidate $candidate, DispatchRequest $request, DateTimeImmutable $now): ?RejectionReason
    {
        return match ($candidate->riderStatus) {
            'suspended', 'banned' => RejectionReason::RiderSuspended,
            'inactive', 'deactivated' => RejectionReason::RiderInactive,
            default => null,
        };
    }
}
