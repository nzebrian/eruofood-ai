<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Application\Port;

use DateTimeImmutable;

/**
 * How busy each rider is, and how recently they have been given work.
 *
 * Two different questions with one owner, because both are answered from the
 * assignment record and asking them separately would mean scanning it twice per
 * dispatch.
 *
 * `activeDeliveryCounts` feeds eligibility — a rider already carrying an order
 * is not offered another. The rest feeds fairness, which only ever reorders.
 */
interface RiderWorkloadQuery
{
    /**
     * Deliveries each rider is currently carrying, keyed by rider id.
     *
     * Riders with none may be absent from the map rather than present with
     * zero; callers coalesce. Counting from the assignment table rather than
     * from Marketplace's delivery status means a rider who has accepted but not
     * yet started still counts as busy — which is the point, since they are.
     *
     * @param list<string> $riderIds
     * @return array<string, int>
     */
    public function activeDeliveryCounts(array $riderIds): array;

    /**
     * Fairness inputs per rider: when they last got work, how much recently,
     * and how long their current unbroken run is.
     *
     * @param list<string> $riderIds
     * @return array<string, array{last_assigned_at: DateTimeImmutable|null, recent_count: int, consecutive_count: int}>
     */
    public function assignmentHistory(array $riderIds, DateTimeImmutable $since): array;
}
