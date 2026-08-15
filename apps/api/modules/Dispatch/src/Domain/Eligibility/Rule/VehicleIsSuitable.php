<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Domain\Eligibility\Rule;

use DateTimeImmutable;
use EruoFood\Dispatch\Domain\Candidate\RiderCandidate;
use EruoFood\Dispatch\Domain\Eligibility\EligibilityRule;
use EruoFood\Dispatch\Domain\Enum\RejectionReason;
use EruoFood\Dispatch\Domain\Request\DispatchRequest;

/**
 * The rider has a vehicle that can actually carry this order.
 *
 * Directional: a car may serve a request for a bike, never the reverse. Without
 * this a bicycle gets offered a forty-kilogram catering order, and the rider
 * either declines — wasting everybody's time — or tries.
 */
final class VehicleIsSuitable implements EligibilityRule
{
    public function key(): string
    {
        return 'vehicle_suitable';
    }

    public function isMandatory(): bool
    {
        return false;
    }

    public function evaluate(RiderCandidate $candidate, DispatchRequest $request, DateTimeImmutable $now): ?RejectionReason
    {
        return $candidate->bestVehicleFor(
            $request->requiredVehicleType(),
            $request->loadKg(),
            $request->loadLitres(),
        ) === null
            ? RejectionReason::VehicleUnsuitable
            : null;
    }
}
