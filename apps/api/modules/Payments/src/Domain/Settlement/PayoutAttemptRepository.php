<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Settlement;

use EruoFood\Shared\Domain\Paginated;

/** Persistence port for {@see PayoutAttempt}. */
interface PayoutAttemptRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?PayoutAttempt;

    /**
     * Insert a new attempt.
     *
     * Throws {@see \EruoFood\Payments\Domain\Exception\PaymentsConflict} on a
     * duplicate attempt number, idempotency key or provider reference. Each of
     * those collisions means two code paths believe they are the same transfer,
     * and the database is the only place that can arbitrate.
     */
    public function insert(PayoutAttempt $attempt): void;

    /** Write an attempt's outcome back. Attempts carry no optimistic version — they are written by exactly one path. */
    public function update(PayoutAttempt $attempt): void;

    /** @return list<PayoutAttempt> newest last, so the sequence reads as a timeline */
    public function forRun(string $settlementRunId): array;

    /** The highest attempt number used for a run, or 0. */
    public function lastAttemptNo(string $settlementRunId): int;

    /**
     * Attempts whose outcome nobody established.
     *
     * The reconciler's queue: `created` rows the process never got past, and
     * `unknown` rows the provider never answered.
     *
     * @return list<PayoutAttempt>
     */
    public function needingReconciliation(int $limit): array;

    /** @return Paginated<PayoutAttempt> */
    public function all(int $page, int $perPage): Paginated;

    /** @return array<string, int> counts by state, for settlement health */
    public function countsByState(): array;
}
