<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Infrastructure\Persistence;

use DateTimeImmutable;
use EruoFood\Dispatch\Application\Port\RiderWorkloadQuery;
use EruoFood\Dispatch\Domain\Assignment\Assignment;
use EruoFood\Dispatch\Domain\Assignment\AssignmentRepository;
use EruoFood\Dispatch\Domain\Enum\AssignmentState;

/**
 * Workload and fairness counters, read from Dispatch's own assignment record.
 *
 * Deliberately not from Marketplace's delivery status. A rider who accepted
 * thirty seconds ago and has not set off yet is busy — they cannot take a
 * second job — but the delivery may still read as barely started. Counting from
 * assignments means "busy" means what a dispatcher means by it.
 */
final readonly class AssignmentWorkloadQuery implements RiderWorkloadQuery
{
    public function __construct(private AssignmentRepository $assignments)
    {
    }

    public function activeDeliveryCounts(array $riderIds): array
    {
        return $this->assignments->activeCountsFor($riderIds);
    }

    public function assignmentHistory(array $riderIds, DateTimeImmutable $since): array
    {
        $history = [];

        foreach (array_unique($riderIds) as $riderId) {
            $assignments = $this->assignments->historyForRider($riderId, $since);

            $history[$riderId] = [
                'last_assigned_at' => isset($assignments[0]) ? $assignments[0]->acceptedAt() : null,
                'recent_count' => count($assignments),
                'consecutive_count' => $this->consecutiveRun($assignments),
            ];
        }

        return $history;
    }

    /**
     * How long the rider's current unbroken run of completed work is.
     *
     * The run breaks at the first assignment that did not end in a delivery: a
     * rider whose last job was cancelled or reassigned has not been on a roll,
     * they have had a bad one, and the consecutive cap exists to rest people
     * who are working hard rather than to punish people whose deliveries went
     * wrong.
     *
     * @param list<Assignment> $newestFirst
     */
    private function consecutiveRun(array $newestFirst): int
    {
        $run = 0;

        foreach ($newestFirst as $assignment) {
            if ($assignment->state() === AssignmentState::Cancelled
                || $assignment->state() === AssignmentState::ReassignmentRequired) {
                break;
            }

            $run++;
        }

        return $run;
    }
}
