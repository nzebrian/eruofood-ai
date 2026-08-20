<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Settlement;

use EruoFood\Payments\Domain\Enum\DiscrepancyKind;
use EruoFood\Payments\Domain\Enum\ReconciliationState;
use EruoFood\Shared\Domain\Paginated;

/** Persistence port for {@see ReconciliationCase}. */
interface ReconciliationCaseRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?ReconciliationCase;

    public function findByIdForUpdate(string $id): ?ReconciliationCase;

    /**
     * Open a case unless an unresolved one already covers the same subject.
     *
     * Returns the existing case when there is one, so a reconciler running
     * every fifteen minutes against a problem that takes two days to fix opens
     * one case rather than two hundred. The partial unique index is what makes
     * this safe under concurrency; this method turns the collision into the
     * existing row rather than an error.
     */
    public function openOrReturnExisting(ReconciliationCase $case): ReconciliationCase;

    public function update(ReconciliationCase $case, int $expectedVersion): void;

    public function findOpenFor(DiscrepancyKind $kind, string $subjectType, string $subjectId): ?ReconciliationCase;

    /** @return Paginated<ReconciliationCase> */
    public function all(?ReconciliationState $state, int $page, int $perPage): Paginated;

    /** @return array<string, int> counts by state, for settlement health */
    public function countsByState(): array;

    /** How many cases are open, investigating or escalated. */
    public function unresolvedCount(): int;
}
