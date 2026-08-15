<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Domain\Eligibility\Rule;

use DateTimeImmutable;
use EruoFood\Dispatch\Domain\Candidate\RiderCandidate;
use EruoFood\Dispatch\Domain\Eligibility\EligibilityRule;
use EruoFood\Dispatch\Domain\Enum\RejectionReason;
use EruoFood\Dispatch\Domain\Request\DispatchRequest;

/**
 * The rider is not already carrying as much as they can.
 *
 * Defaults to one at a time. Batching two deliveries onto one rider is a real
 * optimisation and a real way to deliver two cold meals instead of one hot one;
 * it belongs to a milestone that can measure the trade-off, not to a default.
 */
final readonly class HasNoConflictingDelivery implements EligibilityRule
{
    public function __construct(private int $maxConcurrentDeliveries = 1)
    {
    }

    public function key(): string
    {
        return 'no_conflicting_delivery';
    }

    public function isMandatory(): bool
    {
        return false;
    }

    public function evaluate(RiderCandidate $candidate, DispatchRequest $request, DateTimeImmutable $now): ?RejectionReason
    {
        return $candidate->activeDeliveryCount >= $this->maxConcurrentDeliveries
            ? RejectionReason::RiderHasActiveDelivery
            : null;
    }
}
