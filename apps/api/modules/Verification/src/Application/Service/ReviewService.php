<?php

declare(strict_types=1);

namespace EruoFood\Verification\Application\Service;

use EruoFood\Shared\Domain\Clock;
use EruoFood\Shared\Domain\Paginated;
use EruoFood\Shared\Domain\TransactionManager;
use EruoFood\Verification\Application\Port\SensitiveAccessLogger;
use EruoFood\Verification\Domain\Document\DocumentMetadataRepository;
use EruoFood\Verification\Domain\Enum\ActorType;
use EruoFood\Verification\Domain\Enum\RejectionReason;
use EruoFood\Verification\Domain\Enum\SubjectType;
use EruoFood\Verification\Domain\Enum\VerificationStatus;
use EruoFood\Verification\Domain\Exception\VerificationNotAuthorized;
use EruoFood\Verification\Domain\Exception\VerificationNotFound;
use EruoFood\Verification\Domain\VerificationCase\CaseRepository;
use EruoFood\Verification\Domain\VerificationCase\StatusChange;
use EruoFood\Verification\Domain\VerificationCase\VerificationCase;

/**
 * The back-office side: the review queue, manual decisions, and the one place
 * regulated identity data can be read.
 *
 * The permission split matters more than it might look. Seeing *that* a case is
 * waiting, and seeing *what is inside it*, are different powers:
 *
 * - `verification.read`   — the queue, statuses, reason codes, timestamps.
 *   Enough to run operations and unblock a merchant.
 * - `verification.review` — approve, reject, require reverification.
 * - `verification.pii`    — the identity fields themselves.
 *
 * A general administrator holds the first and not the third, so the ordinary job
 * of clearing a queue never involves opening anybody's documents. That is the
 * "do not automatically give every admin access" requirement expressed as a
 * capability boundary rather than a policy note.
 *
 * Every PII read is audited — granted or denied, and regardless of the reader's
 * role. A SuperAdmin retains the permission, because that is what the role
 * means; what they do not retain is the ability to look invisibly.
 */
final readonly class ReviewService
{
    public const PERMISSION_READ = 'verification.read';

    public const PERMISSION_REVIEW = 'verification.review';

    public const PERMISSION_PII = 'verification.pii';

    public function __construct(
        private CaseRepository $cases,
        private DocumentMetadataRepository $documents,
        private SensitiveAccessLogger $accessLog,
        private VerificationService $verification,
        private TransactionManager $transactions,
        private Clock $clock,
    ) {
    }

    /**
     * The review queue — cases needing a human.
     *
     * Returns cases only; no identity data is loaded, so holding
     * `verification.read` never incidentally exposes PII.
     *
     * @param list<VerificationStatus> $statuses
     * @return Paginated<VerificationCase>
     */
    public function queue(array $statuses, ?SubjectType $subjectType, int $page, int $perPage): Paginated
    {
        $filter = $statuses === []
            ? [VerificationStatus::RequiresReview, VerificationStatus::Processing]
            : $statuses;

        return $this->cases->queue($filter, $subjectType, $page, $perPage);
    }

    public function getCase(string $caseId): VerificationCase
    {
        return $this->cases->findById($caseId) ?? throw VerificationNotFound::of('verification case', $caseId);
    }

    /**
     * The full transition history of a case.
     *
     * Status changes are not sensitive — they carry no identity data — so this
     * sits behind `verification.read` rather than the PII permission.
     *
     * @return list<StatusChange>
     */
    public function history(string $caseId): array
    {
        return $this->cases->history($caseId);
    }

    /**
     * The regulated fields — the only method that returns them.
     *
     * $hasPiiPermission is passed in rather than resolved here so the caller's
     * authorisation context stays explicit at the call site; a future caller
     * cannot obtain PII by forgetting to check.
     *
     * @return list<array<string, mixed>>
     */
    public function sensitiveDocuments(
        string $caseId,
        string $actorId,
        bool $hasPiiPermission,
        ?string $reason = null,
    ): array {
        $case = $this->getCase($caseId);

        if (! $hasPiiPermission) {
            // Refusals are audited too: a rejected attempt to read someone's
            // documents is exactly what a security review wants to see, and
            // logging only successes would let probing go unnoticed.
            $this->accessLog->record(
                caseId: $case->id(),
                actorId: $actorId,
                permission: self::PERMISSION_PII,
                action: 'read_documents',
                result: 'denied',
                reason: $reason,
            );

            throw new VerificationNotAuthorized('You are not permitted to view identity information.');
        }

        $documents = $this->documents->forCase($case->id());

        $this->accessLog->record(
            caseId: $case->id(),
            actorId: $actorId,
            permission: self::PERMISSION_PII,
            action: 'read_documents',
            result: 'granted',
            reason: $reason,
        );

        return array_map(fn ($document): array => [
            'id' => $document->id,
            'document_type' => $document->documentType->value,
            'issuing_country' => $document->issuingCountry,
            // Last four only — the full number was never stored, and the
            // repository is the boundary where it stops being ciphertext.
            'number_last4' => $document->numberLast4,
            'expires_on' => $document->expiresOn?->format('Y-m-d'),
            'created_at' => $document->createdAt->format(DATE_ATOM),
        ], $documents);
    }

    /** Approve a case by hand, e.g. after checking a CAC certificate. */
    public function approve(string $caseId, string $actorId, ?string $note = null): VerificationCase
    {
        $case = $this->decide($caseId, function (VerificationCase $locked) use ($actorId, $note): void {
            $locked->approve(ActorType::Admin, $actorId, null, $this->clock->now(), $note);
        });

        $this->verification->announce($case);

        return $case;
    }

    /** Reject a case by hand, with a classified reason. */
    public function reject(string $caseId, string $actorId, RejectionReason $reason, ?string $note = null): VerificationCase
    {
        $case = $this->decide($caseId, function (VerificationCase $locked) use ($actorId, $reason, $note): void {
            $locked->reject($reason, ActorType::Admin, $actorId, $this->clock->now(), $note);
        });

        $this->verification->announce($case);

        return $case;
    }

    /** Demand that a subject verify again — a data change, a policy change, a concern. */
    public function requireReverification(string $caseId, string $actorId, ?string $note = null): VerificationCase
    {
        $case = $this->decide($caseId, function (VerificationCase $locked) use ($actorId, $note): void {
            $locked->requireReverification(ActorType::Admin, $actorId, $this->clock->now(), $note);
        });

        $this->verification->announce($case);

        return $case;
    }

    /**
     * Apply a reviewer's decision under a row lock.
     *
     * The lock is what stops a reviewer and an arriving webhook from deciding
     * the same case simultaneously and one silently overwriting the other.
     *
     * @param callable(VerificationCase): void $decision
     */
    private function decide(string $caseId, callable $decision): VerificationCase
    {
        return $this->transactions->atomic(function () use ($caseId, $decision): VerificationCase {
            $locked = $this->cases->findByIdForUpdate($caseId)
                ?? throw VerificationNotFound::of('verification case', $caseId);

            $decision($locked);
            $this->cases->save($locked);

            return $locked;
        });
    }
}
