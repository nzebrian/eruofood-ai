<?php

declare(strict_types=1);

namespace EruoFood\Verification\Domain\VerificationCase;

use DateTimeImmutable;
use EruoFood\Shared\Domain\AggregateRoot;
use EruoFood\Verification\Domain\Enum\ActorType;
use EruoFood\Verification\Domain\Enum\CaseType;
use EruoFood\Verification\Domain\Enum\ProviderName;
use EruoFood\Verification\Domain\Enum\RejectionReason;
use EruoFood\Verification\Domain\Enum\SubjectType;
use EruoFood\Verification\Domain\Enum\VerificationLevel;
use EruoFood\Verification\Domain\Enum\VerificationStatus;
use EruoFood\Verification\Domain\Event\ReverificationRequired;
use EruoFood\Verification\Domain\Event\SubjectExpired;
use EruoFood\Verification\Domain\Event\SubjectRejected;
use EruoFood\Verification\Domain\Event\SubjectVerified;
use EruoFood\Verification\Domain\Event\VerificationProcessing;
use EruoFood\Verification\Domain\Event\VerificationSubmitted;
use EruoFood\Verification\Domain\Exception\InvalidVerificationTransition;

/**
 * One subject's verification, and the single place its status can change.
 *
 * Everything that decides a case — a signed provider webhook, a polled decision,
 * a reviewer in the back office, an expiry sweep — goes through {@see transition()},
 * which refuses any move the state machine does not allow and appends an audit
 * event for the one it does. There is deliberately no setter for `status`: an
 * identity decision that gates a rider's income or a merchant's trading must
 * never be reachable by assignment.
 *
 * The aggregate holds *no* identity data. Names, document numbers and dates of
 * birth live on {@see \EruoFood\Verification\Domain\Document\DocumentMetadata},
 * separately readable and separately permissioned, so the ordinary path that
 * asks "is this rider verified?" never loads regulated fields at all.
 */
final class VerificationCase extends AggregateRoot
{
    /** @var list<StatusChange> */
    private array $newStatusChanges = [];

    private function __construct(
        private readonly string $id,
        private readonly SubjectType $subjectType,
        private readonly string $subjectId,
        private readonly CaseType $caseType,
        private readonly string $countryCode,
        private readonly VerificationLevel $requestedLevel,
        private VerificationStatus $status,
        private ?ProviderName $provider,
        private ?string $providerReference,
        private ?string $contactUserId,
        private ?RejectionReason $rejectionReason,
        private ?string $reviewNote,
        private ?DateTimeImmutable $verifiedAt,
        private ?DateTimeImmutable $expiresAt,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
        private readonly int $version,
    ) {
    }

    public static function open(
        string $id,
        SubjectType $subjectType,
        string $subjectId,
        CaseType $caseType,
        string $countryCode,
        VerificationLevel $requestedLevel,
        DateTimeImmutable $now,
        ?string $contactUserId = null,
    ): self {
        $case = new self(
            id: $id,
            subjectType: $subjectType,
            subjectId: $subjectId,
            caseType: $caseType,
            countryCode: strtoupper($countryCode),
            requestedLevel: $requestedLevel,
            status: VerificationStatus::NotStarted,
            provider: null,
            providerReference: null,
            contactUserId: $contactUserId,
            rejectionReason: null,
            reviewNote: null,
            verifiedAt: null,
            expiresAt: null,
            createdAt: $now,
            updatedAt: $now,
            version: 0,
        );

        $case->recordStatusChange(VerificationStatus::NotStarted, ActorType::System, null, 'Case opened', $now);

        return $case;
    }

    /**
     * Status history is deliberately not rehydrated here: it is read from its
     * own table, so the aggregate cannot be used to rewrite it.
     */
    public static function reconstitute(
        string $id,
        SubjectType $subjectType,
        string $subjectId,
        CaseType $caseType,
        string $countryCode,
        VerificationLevel $requestedLevel,
        VerificationStatus $status,
        ?ProviderName $provider,
        ?string $providerReference,
        ?RejectionReason $rejectionReason,
        ?string $reviewNote,
        ?DateTimeImmutable $verifiedAt,
        ?DateTimeImmutable $expiresAt,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
        int $version,
        ?string $contactUserId = null,
    ): self {
        return new self(
            $id,
            $subjectType,
            $subjectId,
            $caseType,
            $countryCode,
            $requestedLevel,
            $status,
            $provider,
            $providerReference,
            $contactUserId,
            $rejectionReason,
            $reviewNote,
            $verifiedAt,
            $expiresAt,
            $createdAt,
            $updatedAt,
            $version,
        );
    }

    /**
     * Attach the provider session the subject will complete, moving the case to
     * Pending.
     */
    public function startAttempt(
        ProviderName $provider,
        string $providerReference,
        ActorType $actorType,
        ?string $actorId,
        DateTimeImmutable $now,
    ): void {
        $this->guard(VerificationStatus::Pending);

        $this->provider = $provider;
        $this->providerReference = $providerReference;
        // A fresh attempt clears the previous verdict so a stale rejection
        // reason cannot be shown against a live attempt.
        $this->rejectionReason = null;
        $this->reviewNote = null;

        $this->apply(VerificationStatus::Pending, $actorType, $actorId, 'Verification attempt started', $now);

        $this->recordThat(new VerificationSubmitted(
            $this->id,
            $this->subjectType->value,
            $this->caseType->value,
            $this->contactUserId,
        ));
    }

    /** The subject submitted; the provider is deciding. */
    public function markProcessing(ActorType $actorType, ?string $actorId, DateTimeImmutable $now): void
    {
        if ($this->status === VerificationStatus::Processing) {
            return;
        }
        $this->guard(VerificationStatus::Processing);
        $this->apply(VerificationStatus::Processing, $actorType, $actorId, 'Provider is processing the submission', $now);

        $this->recordThat(new VerificationProcessing(
            $this->id,
            $this->subjectType->value,
            $this->caseType->value,
            $this->contactUserId,
        ));
    }

    /**
     * Approve. Idempotent: re-approving an already-verified case is a no-op, so
     * a duplicate webhook does not re-publish the event or extend the expiry.
     */
    public function approve(
        ActorType $actorType,
        ?string $actorId,
        ?DateTimeImmutable $expiresAt,
        DateTimeImmutable $now,
        ?string $note = null,
    ): void {
        if ($this->status === VerificationStatus::Verified) {
            return;
        }
        $this->guard(VerificationStatus::Verified);

        $this->verifiedAt = $now;
        $this->expiresAt = $expiresAt;
        $this->rejectionReason = null;
        $this->reviewNote = $note;

        $this->apply(VerificationStatus::Verified, $actorType, $actorId, $note ?? 'Verification approved', $now);

        $this->recordThat(new SubjectVerified(
            $this->id,
            $this->subjectType->value,
            $this->subjectId,
            $this->caseType->value,
            $this->requestedLevel->value,
            $this->expiresAt?->format(DATE_ATOM),
            $this->contactUserId,
        ));
    }

    /** Reject with a classified reason. Idempotent on an already-rejected case. */
    public function reject(
        RejectionReason $reason,
        ActorType $actorType,
        ?string $actorId,
        DateTimeImmutable $now,
        ?string $note = null,
    ): void {
        if ($this->status === VerificationStatus::Rejected && $this->rejectionReason === $reason) {
            return;
        }
        $this->guard(VerificationStatus::Rejected);

        $this->rejectionReason = $reason;
        $this->reviewNote = $note;
        $this->verifiedAt = null;

        $this->apply(VerificationStatus::Rejected, $actorType, $actorId, $note ?? $reason->label(), $now);

        $this->recordThat(new SubjectRejected(
            $this->id,
            $this->subjectType->value,
            $this->subjectId,
            $this->caseType->value,
            $reason->value,
            $reason->isRetryable(),
            $this->contactUserId,
        ));
    }

    /** The provider could not decide; a human must look. */
    public function flagForReview(
        ActorType $actorType,
        ?string $actorId,
        DateTimeImmutable $now,
        ?string $note = null,
    ): void {
        if ($this->status === VerificationStatus::RequiresReview) {
            return;
        }
        $this->guard(VerificationStatus::RequiresReview);
        $this->reviewNote = $note;
        $this->apply(VerificationStatus::RequiresReview, $actorType, $actorId, $note ?? 'Referred for manual review', $now);
    }

    /** A previously good verification aged out. */
    public function expire(ActorType $actorType, ?string $actorId, DateTimeImmutable $now): void
    {
        if ($this->status === VerificationStatus::Expired) {
            return;
        }
        $this->guard(VerificationStatus::Expired);
        $this->apply(VerificationStatus::Expired, $actorType, $actorId, 'Verification expired', $now);

        $this->recordThat(new SubjectExpired(
            $this->id,
            $this->subjectType->value,
            $this->subjectId,
            $this->caseType->value,
            $this->contactUserId,
        ));
    }

    /** Still on file, but the subject must verify again. */
    public function requireReverification(
        ActorType $actorType,
        ?string $actorId,
        DateTimeImmutable $now,
        ?string $note = null,
    ): void {
        if ($this->status === VerificationStatus::ReverificationRequired) {
            return;
        }
        $this->guard(VerificationStatus::ReverificationRequired);
        $this->reviewNote = $note;
        $this->apply(VerificationStatus::ReverificationRequired, $actorType, $actorId, $note ?? 'Reverification required', $now);

        // Two events, deliberately. Consumers project eligibility off
        // SubjectExpired — a subject told to reverify is no more dispatchable
        // than one whose documents lapsed — while the message somebody receives
        // needs to distinguish "this expired" from "we need you to do this
        // again", which read very differently to the person acting on them.
        $this->recordThat(new SubjectExpired(
            $this->id,
            $this->subjectType->value,
            $this->subjectId,
            $this->caseType->value,
            $this->contactUserId,
        ));

        $this->recordThat(new ReverificationRequired(
            $this->id,
            $this->subjectType->value,
            $this->caseType->value,
            $this->contactUserId,
        ));
    }

    /** Whether this case's verification has aged past $now. */
    public function hasExpiredBy(DateTimeImmutable $now): bool
    {
        return $this->status === VerificationStatus::Verified
            && $this->expiresAt !== null
            && $this->expiresAt <= $now;
    }

    public function belongsToSubject(SubjectType $type, string $subjectId): bool
    {
        return $this->subjectType === $type && $this->subjectId === $subjectId;
    }

    /**
     * The value written to the single-open-case unique column: the subject key
     * while the case occupies the slot, null once it closes.
     */
    public function contactUserId(): ?string
    {
        return $this->contactUserId;
    }

    /**
     * Name (or re-name) the person to contact about this case.
     *
     * Re-resolvable because business ownership changes: a store sold between
     * submission and decision should notify whoever owns it now.
     */
    public function contactIs(?string $userId): void
    {
        $this->contactUserId = $userId;
    }

    public function openKey(): ?string
    {
        return $this->status->isOpen()
            ? sprintf('%s:%s:%s', $this->subjectType->value, $this->subjectId, $this->caseType->value)
            : null;
    }

    /** @return list<StatusChange> pulled and cleared by the persistence layer */
    public function releaseStatusChanges(): array
    {
        $changes = $this->newStatusChanges;
        $this->newStatusChanges = [];

        return $changes;
    }

    private function guard(VerificationStatus $next): void
    {
        if (! $this->status->canTransitionTo($next)) {
            throw InvalidVerificationTransition::between($this->status, $next, $this->id);
        }
    }

    private function apply(
        VerificationStatus $next,
        ActorType $actorType,
        ?string $actorId,
        string $note,
        DateTimeImmutable $now,
    ): void {
        $from = $this->status;
        $this->status = $next;
        $this->updatedAt = $now;
        $this->recordStatusChange($from, $actorType, $actorId, $note, $now, $next);
    }

    private function recordStatusChange(
        VerificationStatus $from,
        ActorType $actorType,
        ?string $actorId,
        string $note,
        DateTimeImmutable $now,
        ?VerificationStatus $to = null,
    ): void {
        $this->newStatusChanges[] = new StatusChange(
            caseId: $this->id,
            from: $from,
            to: $to ?? $this->status,
            actorType: $actorType,
            actorId: $actorId,
            reasonCode: $this->rejectionReason?->value,
            note: $note,
            occurredAt: $now,
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    public function subjectType(): SubjectType
    {
        return $this->subjectType;
    }

    public function subjectId(): string
    {
        return $this->subjectId;
    }

    public function caseType(): CaseType
    {
        return $this->caseType;
    }

    public function countryCode(): string
    {
        return $this->countryCode;
    }

    public function requestedLevel(): VerificationLevel
    {
        return $this->requestedLevel;
    }

    public function status(): VerificationStatus
    {
        return $this->status;
    }

    public function provider(): ?ProviderName
    {
        return $this->provider;
    }

    public function providerReference(): ?string
    {
        return $this->providerReference;
    }

    public function rejectionReason(): ?RejectionReason
    {
        return $this->rejectionReason;
    }

    public function reviewNote(): ?string
    {
        return $this->reviewNote;
    }

    public function verifiedAt(): ?DateTimeImmutable
    {
        return $this->verifiedAt;
    }

    public function expiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function version(): int
    {
        return $this->version;
    }
}
