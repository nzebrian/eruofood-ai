<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Domain\Eligibility\Rule;

use DateTimeImmutable;
use EruoFood\Dispatch\Domain\Candidate\RiderCandidate;
use EruoFood\Dispatch\Domain\Eligibility\EligibilityRule;
use EruoFood\Dispatch\Domain\Enum\RejectionReason;
use EruoFood\Dispatch\Domain\Request\DispatchRequest;

/**
 * The rider's position is recent enough to dispatch on.
 *
 * A position with no age is not a position. Before M25 the rider table held
 * latitude and longitude with no timestamp at all, so there was no way to tell
 * a rider who moved five seconds ago from one who last reported on Tuesday —
 * and dispatching on the second one sends a customer's order to wherever
 * somebody's phone last had signal.
 */
final readonly class LocationIsFresh implements EligibilityRule
{
    public function __construct(private int $maxAgeSeconds)
    {
    }

    public function key(): string
    {
        return 'location_fresh';
    }

    public function isMandatory(): bool
    {
        return false;
    }

    public function evaluate(RiderCandidate $candidate, DispatchRequest $request, DateTimeImmutable $now): ?RejectionReason
    {
        return $candidate->locationAgeSeconds($now) > $this->maxAgeSeconds
            ? RejectionReason::LocationStale
            : null;
    }
}
