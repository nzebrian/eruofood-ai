<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Domain\Eligibility\Rule;

use DateTimeImmutable;
use EruoFood\Dispatch\Domain\Candidate\RiderCandidate;
use EruoFood\Dispatch\Domain\Eligibility\EligibilityRule;
use EruoFood\Dispatch\Domain\Enum\RejectionReason;
use EruoFood\Dispatch\Domain\Request\DispatchRequest;

/**
 * The device was reasonably sure where it was.
 *
 * A fix with a two-kilometre accuracy radius is barely a position: scoring it
 * on proximity produces a confident number from a guess, and the rider "nearest"
 * the restaurant may be a suburb away. An unreported accuracy is accepted —
 * plenty of devices do not supply one, and refusing them would exclude working
 * riders for their handset.
 */
final readonly class LocationIsAccurate implements EligibilityRule
{
    public function __construct(private float $maxAccuracyMetres)
    {
    }

    public function key(): string
    {
        return 'location_accurate';
    }

    public function isMandatory(): bool
    {
        return false;
    }

    public function evaluate(RiderCandidate $candidate, DispatchRequest $request, DateTimeImmutable $now): ?RejectionReason
    {
        if ($candidate->locationAccuracyMetres === null) {
            return null;
        }

        return $candidate->locationAccuracyMetres > $this->maxAccuracyMetres
            ? RejectionReason::LocationInaccurate
            : null;
    }
}
