<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Domain\Eligibility\Rule;

use DateTimeImmutable;
use EruoFood\Dispatch\Domain\Candidate\RiderCandidate;
use EruoFood\Dispatch\Domain\Eligibility\EligibilityRule;
use EruoFood\Dispatch\Domain\Enum\RejectionReason;
use EruoFood\Dispatch\Domain\Request\DispatchRequest;

/**
 * A rider who has gone offline is not offered work.
 *
 * Not a judgement — an offline rider has finished their shift, and offering
 * them a delivery they will not see is how a customer waits forty-five seconds
 * for a timeout that was never going to be answered.
 */
final class RiderIsAvailable implements EligibilityRule
{
    public function key(): string
    {
        return 'rider_available';
    }

    public function isMandatory(): bool
    {
        return false;
    }

    public function evaluate(RiderCandidate $candidate, DispatchRequest $request, DateTimeImmutable $now): ?RejectionReason
    {
        return in_array($candidate->riderStatus, ['online', 'available', 'idle'], true)
            ? null
            : RejectionReason::RiderUnavailable;
    }
}
