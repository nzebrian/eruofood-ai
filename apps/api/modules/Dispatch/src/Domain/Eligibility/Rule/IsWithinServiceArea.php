<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Domain\Eligibility\Rule;

use DateTimeImmutable;
use EruoFood\Dispatch\Application\Port\ServiceAreaCheck;
use EruoFood\Dispatch\Domain\Candidate\RiderCandidate;
use EruoFood\Dispatch\Domain\Eligibility\EligibilityRule;
use EruoFood\Dispatch\Domain\Enum\RejectionReason;
use EruoFood\Dispatch\Domain\Request\DispatchRequest;

/**
 * The rider is somewhere the platform actually operates.
 *
 * Answered by M25's delivery zones through a port — Dispatch does not own a
 * second definition of where the platform serves, and a second definition is
 * exactly how a rider ends up eligible for dispatch in an area the checkout
 * refuses to quote for.
 *
 * When zones are not configured the check passes. Refusing every rider because
 * nobody has drawn a polygon yet would take a working platform offline, and
 * "no zones" means "not zoned", not "nowhere is served".
 */
final readonly class IsWithinServiceArea implements EligibilityRule
{
    public function __construct(private ServiceAreaCheck $serviceArea)
    {
    }

    public function key(): string
    {
        return 'within_service_area';
    }

    public function isMandatory(): bool
    {
        return false;
    }

    public function evaluate(RiderCandidate $candidate, DispatchRequest $request, DateTimeImmutable $now): ?RejectionReason
    {
        return $this->serviceArea->covers($candidate->latitude, $candidate->longitude)
            ? null
            : RejectionReason::OutsideServiceArea;
    }
}
