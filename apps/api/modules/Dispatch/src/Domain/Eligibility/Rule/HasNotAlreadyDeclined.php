<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Domain\Eligibility\Rule;

use DateTimeImmutable;
use EruoFood\Dispatch\Domain\Candidate\RiderCandidate;
use EruoFood\Dispatch\Domain\Eligibility\EligibilityRule;
use EruoFood\Dispatch\Domain\Enum\RejectionReason;
use EruoFood\Dispatch\Domain\Request\DispatchRequest;

/**
 * A rider who already said no is not asked again.
 *
 * Re-offering the same delivery to somebody who declined it wastes their
 * attention and costs the customer another timeout window. The exclusion is
 * per request, not global — declining one order says nothing about the next.
 */
final readonly class HasNotAlreadyDeclined implements EligibilityRule
{
    /** @param list<string> $declinedRiderIds */
    public function __construct(private array $declinedRiderIds)
    {
    }

    public function key(): string
    {
        return 'not_already_declined';
    }

    public function isMandatory(): bool
    {
        return false;
    }

    public function evaluate(RiderCandidate $candidate, DispatchRequest $request, DateTimeImmutable $now): ?RejectionReason
    {
        return in_array($candidate->riderId, $this->declinedRiderIds, true)
            ? RejectionReason::AlreadyDeclined
            : null;
    }
}
