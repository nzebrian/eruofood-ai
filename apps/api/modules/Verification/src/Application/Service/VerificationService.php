<?php

declare(strict_types=1);

namespace EruoFood\Verification\Application\Service;

use DateTimeImmutable;
use EruoFood\Shared\Domain\Clock;
use EruoFood\Shared\Domain\EventBus;
use EruoFood\Shared\Domain\TransactionManager;
use EruoFood\Verification\Application\DTO\VerificationDecision;
use EruoFood\Verification\Application\DTO\VerificationRequest;
use EruoFood\Verification\Application\Port\FieldEncryptor;
use EruoFood\Verification\Application\Port\VerificationProviderRegistry;
use EruoFood\Verification\Domain\Attempt\AttemptRepository;
use EruoFood\Verification\Domain\Attempt\VerificationAttempt;
use EruoFood\Verification\Domain\Document\DocumentMetadata;
use EruoFood\Verification\Domain\Document\DocumentMetadataRepository;
use EruoFood\Verification\Domain\Enum\ActorType;
use EruoFood\Verification\Domain\Enum\CaseType;
use EruoFood\Verification\Domain\Enum\SubjectType;
use EruoFood\Verification\Domain\Enum\VerificationLevel;
use EruoFood\Verification\Domain\Enum\VerificationStatus;
use EruoFood\Verification\Domain\Exception\VerificationConflict;
use EruoFood\Verification\Domain\Exception\VerificationNotFound;
use EruoFood\Verification\Domain\VerificationCase\CaseRepository;
use EruoFood\Verification\Domain\VerificationCase\VerificationCase;
use Throwable;

/**
 * The lifecycle of a verification case: open it, start a provider attempt, apply
 * a decision.
 *
 * Two structural rules run through everything here.
 *
 * **The provider call never happens inside a transaction.** Opening a session is
 * a network round trip; holding a row lock across it would stall every other
 * decision on that case for the length of an external request, and its effect
 * could not be rolled back anyway. So the shape is always: commit the case,
 * call the provider, commit the outcome — the same reserve/call/settle pattern
 * M23 established for payments.
 *
 * **A decision is only ever applied under a row lock.** A signed webhook, a
 * polled reconciliation and a reviewer can all decide the same case, and the
 * state machine is only a real guarantee if they cannot interleave.
 */
final readonly class VerificationService
{
    public function __construct(
        private CaseRepository $cases,
        private AttemptRepository $attempts,
        private DocumentMetadataRepository $documents,
        private VerificationProviderRegistry $providers,
        private FieldEncryptor $encryptor,
        private TransactionManager $transactions,
        private EventBus $events,
        private Clock $clock,
        private int $identityValidityDays,
        private int $businessValidityDays,
        /**
         * Resolves the account to contact about a business case.
         *
         * A callable rather than a repository because business ownership belongs
         * to Marketplace and Commerce; Verification asks a question and is
         * handed an answer, without importing either.
         *
         * @var (callable(string): ?string)|null
         */
        private mixed $businessContact = null,
    ) {
    }

    /**
     * Open a case for a subject, or return the one already open.
     *
     * Idempotent by design: a subject who taps "verify" twice gets the same
     * case, not two competing ones. The single-open-case rule is additionally
     * enforced by a unique column, so a genuine race loses at the database
     * rather than creating a duplicate.
     */
    public function openCase(
        SubjectType $subjectType,
        string $subjectId,
        CaseType $caseType,
        string $countryCode,
        ?VerificationLevel $requestedLevel = null,
    ): VerificationCase {
        return $this->transactions->atomic(function () use ($subjectType, $subjectId, $caseType, $countryCode, $requestedLevel): VerificationCase {
            $existing = $this->cases->findOpenFor($subjectType, $subjectId, $caseType);
            if ($existing !== null) {
                return $existing;
            }

            // A case that closed but may be retried — rejected, expired, or sent
            // back for reverification — is reopened rather than replaced. One
            // case per subject and case type stays the durable record, carrying
            // every attempt and every transition, so "why is this rider
            // verified?" is answered from one history instead of by hunting
            // across a row per round.
            $previous = $this->cases->findLatestFor($subjectType, $subjectId, $caseType);
            if ($previous !== null && $previous->status()->canTransitionTo(VerificationStatus::Pending)) {
                return $previous;
            }

            $case = VerificationCase::open(
                id: $this->cases->nextIdentity(),
                subjectType: $subjectType,
                subjectId: $subjectId,
                caseType: $caseType,
                countryCode: $countryCode,
                requestedLevel: $requestedLevel ?? $subjectType->requiredLevel(),
                now: $this->clock->now(),
                contactUserId: $this->contactFor($subjectType, $subjectId),
            );
            $this->cases->save($case);

            return $case;
        });
    }

    /**
     * Who to tell about this case.
     *
     * A rider or customer case is about a person, so the subject is the contact.
     * A business case is about a company — nobody can be emailed at a vendor id
     * — so the owning account is looked up once, here, rather than on every
     * notification.
     */
    private function contactFor(SubjectType $subjectType, string $subjectId): ?string
    {
        if ($subjectType !== SubjectType::Business) {
            return $subjectId;
        }

        if (! is_callable($this->businessContact)) {
            return null;
        }

        $owner = ($this->businessContact)($subjectId);

        return is_string($owner) && $owner !== '' ? $owner : null;
    }

    /**
     * Create a provider session for the case and move it to Pending.
     *
     * @param list<string> $requiredChecks e.g. ['document', 'liveness', 'driving_licence']
     */
    public function startVerification(
        string $caseId,
        array $requiredChecks = [],
        ActorType $actorType = ActorType::Subject,
        ?string $actorId = null,
    ): VerificationCase {
        $case = $this->cases->findById($caseId) ?? throw VerificationNotFound::of('verification case', $caseId);

        if ($case->status()->isVerified()) {
            throw new VerificationConflict('This subject is already verified.');
        }

        $provider = $this->providers->resolve($case->caseType(), $case->countryCode());

        // Outside any transaction: an external call must not hold a lock.
        $session = $provider->createSession(new VerificationRequest(
            caseId: $case->id(),
            subjectType: $case->subjectType(),
            caseType: $case->caseType(),
            countryCode: $case->countryCode(),
            requiredChecks: $requiredChecks,
        ));

        $settled = $this->transactions->atomic(function () use ($caseId, $session, $actorType, $actorId): VerificationCase {
            $locked = $this->cases->findByIdForUpdate($caseId)
                ?? throw VerificationNotFound::of('verification case', $caseId);

            $now = $this->clock->now();

            $locked->startAttempt($session->provider, $session->providerReference, $actorType, $actorId, $now);
            $this->cases->save($locked);

            $this->attempts->save(VerificationAttempt::start(
                $this->attempts->nextIdentity(),
                $locked->id(),
                $session->provider,
                $session->providerReference,
                $now,
            ));

            // A provider that decides immediately (a registry lookup, a manual
            // route straight to the queue) reports its status on the session, so
            // honour it rather than leaving the case falsely Pending.
            if ($session->status !== VerificationStatus::Pending) {
                $this->applyStatus($locked, $session->status, null, ActorType::Provider, null, $now);
                $this->cases->save($locked);
            }

            return $locked;
        });

        // Published after the transaction commits, never inside it: a
        // subscriber must not be told a verification was submitted while a
        // rollback is still possible.
        $this->announce($settled);

        return $settled;
    }

    /**
     * Apply a provider decision to a case, under a row lock.
     *
     * Used by the webhook path and by reconciliation alike, so the two cannot
     * diverge in how a verdict is interpreted. Runs inside the caller's
     * transaction; events are published by the caller after it commits.
     */
    public function applyDecision(
        string $caseId,
        VerificationDecision $decision,
        ActorType $actorType,
        ?string $actorId,
    ): VerificationCase {
        $case = $this->cases->findByIdForUpdate($caseId)
            ?? throw VerificationNotFound::of('verification case', $caseId);

        $now = $this->clock->now();

        $attempt = $case->providerReference() !== null
            ? $this->attempts->findByProviderReference($case->providerReference())
            : null;

        if ($attempt !== null) {
            $attempt->decide($decision->status, $decision->rawStatus, $decision->reason, $now);
            $this->attempts->save($attempt);
        }

        $this->applyStatus($case, $decision->status, $decision, $actorType, $actorId, $now);
        $this->cases->save($case);

        if ($decision->status->isVerified()) {
            $this->recordDocuments($case, $decision, $now);
        }

        return $case;
    }

    /** Publish whatever a committed decision implies. Called after the transaction. */
    public function announce(VerificationCase $case): void
    {
        foreach ($case->releaseEvents() as $event) {
            $this->events->publish($event);
        }
    }

    public function getCase(string $caseId): VerificationCase
    {
        return $this->cases->findById($caseId) ?? throw VerificationNotFound::of('verification case', $caseId);
    }

    /** The subject's current case of this type, whether open or closed. */
    public function latestFor(SubjectType $subjectType, string $subjectId, CaseType $caseType): ?VerificationCase
    {
        return $this->cases->findLatestFor($subjectType, $subjectId, $caseType);
    }

    /**
     * Move the case into the status a decision calls for.
     *
     * Every branch goes through an aggregate method that validates the
     * transition; there is no path here that assigns a status directly.
     */
    private function applyStatus(
        VerificationCase $case,
        VerificationStatus $status,
        ?VerificationDecision $decision,
        ActorType $actorType,
        ?string $actorId,
        DateTimeImmutable $now,
    ): void {
        match ($status) {
            VerificationStatus::Verified => $case->approve(
                $actorType,
                $actorId,
                $this->expiryFor($case->caseType(), $now),
                $now,
                $decision?->note,
            ),
            VerificationStatus::Rejected => $case->reject(
                $decision->reason ?? \EruoFood\Verification\Domain\Enum\RejectionReason::ProviderError,
                $actorType,
                $actorId,
                $now,
                $decision?->note,
            ),
            VerificationStatus::RequiresReview => $case->flagForReview($actorType, $actorId, $now, $decision?->note),
            VerificationStatus::Processing => $case->markProcessing($actorType, $actorId, $now),
            VerificationStatus::Expired => $case->expire($actorType, $actorId, $now),
            VerificationStatus::ReverificationRequired => $case->requireReverification($actorType, $actorId, $now, $decision?->note),
            // Pending and NotStarted are reached by starting an attempt, never
            // by a decision, so a provider reporting one is a no-op here.
            VerificationStatus::Pending, VerificationStatus::NotStarted => null,
        };
    }

    /**
     * Store the reduced document facts.
     *
     * The number is cut to its last four characters *before* encryption and
     * before it touches the repository — there is no code path by which a full
     * document number reaches storage.
     */
    private function recordDocuments(VerificationCase $case, VerificationDecision $decision, DateTimeImmutable $now): void
    {
        foreach ($decision->documents as $summary) {
            $last4 = DocumentMetadata::lastFourOf($summary->documentNumber);

            $this->documents->save(new DocumentMetadata(
                id: $this->documents->nextIdentity(),
                caseId: $case->id(),
                documentType: $summary->type,
                issuingCountry: $summary->issuingCountry,
                numberLast4: $last4 === null ? null : $this->encryptor->encrypt($last4),
                expiresOn: $this->parseDate($summary->expiresOn),
                providerReference: $case->providerReference(),
                createdAt: $now,
            ));
        }
    }

    private function expiryFor(CaseType $caseType, DateTimeImmutable $now): ?DateTimeImmutable
    {
        $days = $caseType === CaseType::Business ? $this->businessValidityDays : $this->identityValidityDays;

        return $days > 0 ? $now->modify(sprintf('+%d days', $days)) : null;
    }

    private function parseDate(?string $value): ?DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            // A provider date we cannot parse is dropped rather than guessed at;
            // the case is still verified, we simply hold no expiry for it.
            return null;
        }
    }
}
