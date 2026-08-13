<?php

declare(strict_types=1);

namespace EruoFood\Verification\Domain\VerificationCase;

use DateTimeImmutable;
use EruoFood\Shared\Domain\Paginated;
use EruoFood\Verification\Domain\Enum\CaseType;
use EruoFood\Verification\Domain\Enum\SubjectType;
use EruoFood\Verification\Domain\Enum\VerificationStatus;

/** Persistence port for the {@see VerificationCase} aggregate and its history. */
interface CaseRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?VerificationCase;

    /**
     * Read the case holding an exclusive row lock until the surrounding
     * transaction ends.
     *
     * Required wherever a status is read and then written back. A provider
     * webhook and a reviewer's decision can land at the same instant; without
     * the lock both read the same status, both find their transition legal, and
     * one silently overwrites the other.
     */
    public function findByIdForUpdate(string $id): ?VerificationCase;

    /** The case currently occupying the subject's open slot, if any. */
    public function findOpenFor(SubjectType $type, string $subjectId, CaseType $caseType): ?VerificationCase;

    /** The subject's most recent case of this type, open or closed. */
    public function findLatestFor(SubjectType $type, string $subjectId, CaseType $caseType): ?VerificationCase;

    /** Resolve a provider session reference back to its case, under a row lock. */
    public function findByProviderReferenceForUpdate(string $providerReference): ?VerificationCase;

    /**
     * The review queue.
     *
     * @param list<VerificationStatus> $statuses
     * @return Paginated<VerificationCase>
     */
    public function queue(array $statuses, ?SubjectType $subjectType, int $page, int $perPage): Paginated;

    /**
     * Cases stuck awaiting a provider decision since before $before — the input
     * to reconciliation when a webhook is lost.
     *
     * @return list<VerificationCase>
     */
    public function stalledSince(DateTimeImmutable $before, int $limit): array;

    /**
     * Verified cases whose validity has run out.
     *
     * @return list<VerificationCase>
     */
    public function expiredBy(DateTimeImmutable $now, int $limit): array;

    /** @return list<StatusChange> */
    public function history(string $caseId): array;

    /**
     * Persist the case and append its new history entries atomically.
     *
     * @throws \EruoFood\Shared\Domain\Exception\ConcurrencyConflict when the row
     *                                                               changed since the aggregate was loaded
     */
    public function save(VerificationCase $case): void;
}
