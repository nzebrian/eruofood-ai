<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Domain\Assignment;

use DateTimeImmutable;

/** Persistence port for {@see Assignment}. */
interface AssignmentRepository
{
    public function nextIdentity(): string;

    public function find(string $id): ?Assignment;

    /** The live assignment for a delivery — mirrors the partial unique index. */
    public function activeForDelivery(string $deliveryId): ?Assignment;

    /** What this rider is currently carrying — mirrors the partial unique index. */
    public function activeForRider(string $riderId): ?Assignment;

    public function forOffer(string $offerId): ?Assignment;

    /**
     * Every delivery somebody is carrying right now, newest first.
     *
     * The Control Centre's live view. Uses the same "active" list the partial
     * unique indexes are built from, so what an operator sees and what the
     * database enforces cannot come to mean different things.
     *
     * @return list<Assignment>
     */
    public function active(int $limit = 100): array;

    /**
     * A rider's assignments since a point in time, newest first.
     *
     * The fairness and workload input. Read from assignments rather than from
     * Marketplace's delivery status, because a rider who has accepted but not
     * yet set off is still busy.
     *
     * @return list<Assignment>
     */
    public function historyForRider(string $riderId, DateTimeImmutable $since, int $limit = 50): array;

    /**
     * Live assignments for many riders at once, keyed by rider id.
     *
     * @param list<string> $riderIds
     * @return array<string, int>
     */
    public function activeCountsFor(array $riderIds): array;

    /**
     * Save, honouring the aggregate's version.
     *
     * The insert is where two riders accepting the same delivery collide: the
     * partial unique index rejects the second, and the caller turns that into
     * an honest "somebody else got there first" rather than a 500.
     */
    public function save(Assignment $assignment): void;
}
