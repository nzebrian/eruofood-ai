<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Domain\Eligibility\Rule;

use DateTimeImmutable;
use EruoFood\Dispatch\Domain\Candidate\RiderCandidate;
use EruoFood\Dispatch\Domain\Eligibility\EligibilityRule;
use EruoFood\Dispatch\Domain\Enum\RejectionReason;
use EruoFood\Dispatch\Domain\Request\DispatchRequest;

/**
 * The rider has at least one vehicle an operator has approved.
 *
 * Mandatory, and the rule that makes the whole vehicle domain worth having.
 *
 * The three ways this can fail are reported separately, because they are three
 * different messages to a rider whose earnings just stopped:
 *
 * - **no vehicle at all** — "register one"; this is where the legacy on-foot
 *   riders land after the M26 backfill;
 * - **owns one, nobody has approved it** — "we are working on it", and it is an
 *   operator queue problem, not the rider's;
 * - **approved, but the paperwork lapsed** — "renew your insurance".
 *
 * Collapsing them into one "not eligible" is exactly the unhelpful answer this
 * context exists to stop giving.
 */
final class HasDispatchableVehicle implements EligibilityRule
{
    public function key(): string
    {
        return 'vehicle_verified';
    }

    public function isMandatory(): bool
    {
        return true;
    }

    public function evaluate(RiderCandidate $candidate, DispatchRequest $request, DateTimeImmutable $now): ?RejectionReason
    {
        if ($candidate->hasAnyVehicle()) {
            return null;
        }

        if (! $candidate->ownsAnyVehicle()) {
            return RejectionReason::NoActiveVehicle;
        }

        if ($candidate->allVehiclesHaveLapsedDocuments($now)) {
            return RejectionReason::VehicleDocumentsExpired;
        }

        return RejectionReason::VehicleNotVerified;
    }
}
