<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Settlement;

use DateTimeImmutable;
use EruoFood\Payments\Domain\Enum\SettlementRunState;
use EruoFood\Shared\Domain\Paginated;

/** Persistence port for {@see SettlementRun} and its {@see SettlementLine}s. */
interface SettlementRunRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?SettlementRun;

    /**
     * Read the run holding an exclusive row lock until the surrounding
     * transaction ends.
     *
     * The first of the four concurrency layers. Every money-moving path reads
     * through this, never through {@see findById()}.
     */
    public function findByIdForUpdate(string $id): ?SettlementRun;

    /**
     * Take the merchant's payable lock before computing or reserving.
     *
     * There is no merchant row to lock — payable is derived, not stored — so
     * this takes an advisory lock keyed on the merchant instead. Always
     * acquired merchant-first, so concurrent runs for different merchants never
     * deadlock against each other.
     *
     * A no-op on SQLite, which serialises writes anyway. The guarantee is
     * proven on PostgreSQL by the concurrency harness.
     */
    public function lockMerchant(string $merchantType, string $merchantId): void;

    /**
     * Persist a new run and its lines in one write.
     *
     * Throws {@see \EruoFood\Payments\Domain\Exception\PaymentsConflict} when the
     * live-window index or an accrual line collides — the last-line guarantees.
     *
     * @param list<SettlementLine> $lines
     */
    public function insert(SettlementRun $run, array $lines): void;

    /**
     * Write a mutated run back, checking the optimistic version.
     *
     * Throws {@see \EruoFood\Shared\Domain\Exception\ConcurrencyConflict} when the
     * stored version has moved on — the caller was holding a stale copy.
     */
    public function update(SettlementRun $run, int $expectedVersion): void;

    /**
     * Release a run's lines so its accruals become settleable again.
     *
     * Only legal for a run in a state that {@see SettlementRunState::releasesAccruals()}
     * accepts; implementations assert it rather than trusting the caller,
     * because deleting the lines of a live run would free accruals that are
     * about to be paid.
     */
    public function releaseLines(SettlementRun $run): void;

    /** @return list<SettlementLine> */
    public function linesFor(string $runId): array;

    /** @return Paginated<SettlementRun> */
    public function all(?SettlementRunState $state, int $page, int $perPage): Paginated;

    /** @return Paginated<SettlementRun> */
    public function forMerchant(string $merchantType, string $merchantId, int $page, int $perPage): Paginated;

    /**
     * Runs stuck in a state that needs the reconciler's attention.
     *
     * @return list<SettlementRun>
     */
    public function awaitingReconciliation(int $limit): array;

    /**
     * A live run already covering this window, if one exists.
     *
     * A courtesy check so the caller can report a useful error instead of a
     * constraint violation. The index remains the guarantee.
     */
    public function liveRunForWindow(
        string $merchantType,
        string $merchantId,
        string $currency,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd,
    ): ?SettlementRun;

    /**
     * Counts by state, for the settlement-health view.
     *
     * @return array<string, int>
     */
    public function countsByState(): array;

    /**
     * The total actually paid out of `MerchantPayable` into `Payouts`.
     *
     * **Succeeded runs only**, and the distinction is not pedantry. A run that
     * is drafted, pending, processing or unknown has *reserved* its accruals —
     * they cannot be settled again — but has posted nothing to the ledger. The
     * reconciler compares this against the `MerchantPayable` ledger balance, so
     * counting reserved-but-unpaid lines here would report a drift for every
     * settlement currently in flight: a false alarm generator, firing hardest
     * exactly when settlement is busiest.
     *
     * The *other* number — what a merchant may still be settled for, which does
     * subtract reservations — is
     * {@see PayableAccrualRepository::derivedPayableMinor()}.
     */
    public function paidOutNetMinor(): int;
}
