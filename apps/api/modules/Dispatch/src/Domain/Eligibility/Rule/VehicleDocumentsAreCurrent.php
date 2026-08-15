<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Domain\Eligibility\Rule;

use DateTimeImmutable;
use EruoFood\Dispatch\Domain\Candidate\RiderCandidate;
use EruoFood\Dispatch\Domain\Eligibility\EligibilityRule;
use EruoFood\Dispatch\Domain\Enum\RejectionReason;
use EruoFood\Dispatch\Domain\Request\DispatchRequest;
use EruoFood\Dispatch\Domain\Vehicle\Vehicle;

/**
 * Every vehicle offered to this delivery still has current paperwork.
 *
 * Mandatory, and re-checked here against the dispatch clock rather than trusted
 * from the query that built the pool. That looks like belt and braces and is
 * not: the candidate pool is assembled once and may be evaluated seconds later,
 * across a policy expiry, and — more importantly — this is the rule that still
 * holds if the SQL predicate in the repository is ever changed to disagree with
 * the aggregate.
 *
 * A defence that only exists in the query that finds candidates is a defence
 * that disappears the day somebody writes a second query.
 */
final class VehicleDocumentsAreCurrent implements EligibilityRule
{
    public function key(): string
    {
        return 'vehicle_documents_current';
    }

    public function isMandatory(): bool
    {
        return true;
    }

    public function evaluate(RiderCandidate $candidate, DispatchRequest $request, DateTimeImmutable $now): ?RejectionReason
    {
        if ($candidate->vehicles === []) {
            // Nothing to check. `HasDispatchableVehicle` owns that rejection,
            // and reporting it twice would double-count in the breakdown.
            return null;
        }

        foreach ($candidate->vehicles as $vehicle) {
            if ($this->isUsable($vehicle, $now)) {
                return null;
            }
        }

        return RejectionReason::VehicleDocumentsExpired;
    }

    private function isUsable(Vehicle $vehicle, DateTimeImmutable $now): bool
    {
        return $vehicle->isDispatchable($now);
    }
}
