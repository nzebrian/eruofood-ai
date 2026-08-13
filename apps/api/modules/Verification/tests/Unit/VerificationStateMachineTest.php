<?php

declare(strict_types=1);

use EruoFood\Verification\Domain\Enum\ActorType;
use EruoFood\Verification\Domain\Enum\CaseType;
use EruoFood\Verification\Domain\Enum\ProviderName;
use EruoFood\Verification\Domain\Enum\RejectionReason;
use EruoFood\Verification\Domain\Enum\SubjectType;
use EruoFood\Verification\Domain\Enum\VerificationLevel;
use EruoFood\Verification\Domain\Enum\VerificationStatus;
use EruoFood\Verification\Domain\Exception\InvalidVerificationTransition;
use EruoFood\Verification\Domain\VerificationCase\VerificationCase;

/**
 * M24 — the verification state machine.
 *
 * A status decides whether a rider earns and whether a merchant trades, so the
 * transition table is the safety property worth testing exhaustively rather than
 * by example.
 */
function newCase(SubjectType $subject = SubjectType::Rider, CaseType $type = CaseType::Identity): VerificationCase
{
    return VerificationCase::open(
        'case-'.bin2hex(random_bytes(4)),
        $subject,
        'subject-'.bin2hex(random_bytes(4)),
        $type,
        'NG',
        VerificationLevel::Identity,
        new DateTimeImmutable('2026-01-01T00:00:00Z'),
    );
}

function started(SubjectType $subject = SubjectType::Rider): VerificationCase
{
    $case = newCase($subject);
    $case->startAttempt(ProviderName::Mock, 'ref-1', ActorType::Subject, 'u1', new DateTimeImmutable());

    return $case;
}

it('opens as not started and records that in its history', function (): void {
    $case = newCase();

    expect($case->status())->toBe(VerificationStatus::NotStarted)
        ->and($case->releaseStatusChanges())->toHaveCount(1);
});

it('walks the happy path from not started to verified', function (): void {
    $case = newCase();
    $now = new DateTimeImmutable();

    $case->startAttempt(ProviderName::Mock, 'ref-1', ActorType::Subject, 'u1', $now);
    expect($case->status())->toBe(VerificationStatus::Pending);

    $case->markProcessing(ActorType::Provider, 'mock', $now);
    expect($case->status())->toBe(VerificationStatus::Processing);

    $case->approve(ActorType::Provider, 'mock', $now->modify('+1 year'), $now);
    expect($case->status())->toBe(VerificationStatus::Verified)
        ->and($case->verifiedAt())->not->toBeNull()
        ->and($case->expiresAt())->not->toBeNull();
});

it('records every transition with an actor and a reason', function (): void {
    $case = started();
    $case->releaseStatusChanges(); // drain the opening entries

    $case->reject(RejectionReason::FaceMismatch, ActorType::Provider, 'didit', new DateTimeImmutable(), 'no match');

    $changes = $case->releaseStatusChanges();

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->from)->toBe(VerificationStatus::Pending)
        ->and($changes[0]->to)->toBe(VerificationStatus::Rejected)
        ->and($changes[0]->actorType)->toBe(ActorType::Provider)
        ->and($changes[0]->actorId)->toBe('didit')
        ->and($changes[0]->reasonCode)->toBe('face_mismatch');
});

it('refuses to un-verify a verified case by rejecting it', function (): void {
    $case = started();
    $case->approve(ActorType::Provider, 'mock', null, new DateTimeImmutable());

    // A verified case may only decay (expire / require reverification). A late
    // "declined" webhook must not silently strip somebody's verification.
    expect(fn () => $case->reject(RejectionReason::DocumentExpired, ActorType::Provider, 'mock', new DateTimeImmutable()))
        ->toThrow(InvalidVerificationTransition::class);

    expect($case->status())->toBe(VerificationStatus::Verified);
});

it('refuses to approve straight from not started', function (): void {
    $case = newCase();

    expect(fn () => $case->approve(ActorType::Admin, 'admin-1', null, new DateTimeImmutable()))
        ->toThrow(InvalidVerificationTransition::class);
});

it('lets a closed case reopen with a new attempt', function (): void {
    $case = started();
    $case->reject(RejectionReason::DocumentUnreadable, ActorType::Provider, 'mock', new DateTimeImmutable());

    $case->startAttempt(ProviderName::Mock, 'ref-2', ActorType::Subject, 'u1', new DateTimeImmutable());

    expect($case->status())->toBe(VerificationStatus::Pending)
        // A fresh attempt clears the stale verdict so a previous reason is not
        // shown against a live attempt.
        ->and($case->rejectionReason())->toBeNull()
        ->and($case->providerReference())->toBe('ref-2');
});

it('treats a repeated approval as a no-op rather than an error', function (): void {
    $case = started();
    $now = new DateTimeImmutable();
    $case->approve(ActorType::Provider, 'mock', $now->modify('+1 year'), $now);
    $firstVerifiedAt = $case->verifiedAt();
    $case->releaseEvents();

    // A duplicate webhook must not re-publish the event or extend the expiry.
    $case->approve(ActorType::Provider, 'mock', $now->modify('+5 years'), $now->modify('+1 day'));

    expect($case->verifiedAt())->toEqual($firstVerifiedAt)
        ->and($case->releaseEvents())->toBeEmpty();
});

it('publishes a verified event carrying the subject and level', function (): void {
    $case = started();
    $case->releaseEvents();
    $case->approve(ActorType::Provider, 'mock', null, new DateTimeImmutable());

    $events = $case->releaseEvents();

    expect($events)->toHaveCount(1)
        ->and($events[0]->eventName())->toBe('verification.subject_verified')
        ->and($events[0]->subjectType)->toBe('rider')
        ->and($events[0]->level)->toBe('identity');
});

it('occupies the subject open slot only while in flight', function (): void {
    $case = started();
    expect($case->openKey())->toBe(sprintf('rider:%s:identity', $case->subjectId()));

    $case->approve(ActorType::Provider, 'mock', null, new DateTimeImmutable());

    // Released once closed, so a reverification can open a new case later.
    expect($case->openKey())->toBeNull();
});

it('reports expiry only for a verified case past its date', function (): void {
    $now = new DateTimeImmutable('2026-06-01T00:00:00Z');
    $case = started();
    $case->approve(ActorType::Provider, 'mock', new DateTimeImmutable('2026-05-01T00:00:00Z'), $now);

    expect($case->hasExpiredBy($now))->toBeTrue();

    $fresh = started();
    $fresh->approve(ActorType::Provider, 'mock', new DateTimeImmutable('2027-01-01T00:00:00Z'), $now);
    expect($fresh->hasExpiredBy($now))->toBeFalse();
});

it('allows exactly the documented transitions and no others', function (): void {
    // The full matrix, asserted rather than sampled: an accidental widening of
    // the table is the kind of change that silently lets a rejected rider
    // become verified.
    $expected = [
        'not_started' => ['pending'],
        'pending' => ['processing', 'requires_review', 'verified', 'rejected', 'expired'],
        'processing' => ['requires_review', 'verified', 'rejected', 'expired'],
        'requires_review' => ['verified', 'rejected', 'expired', 'reverification_required'],
        'verified' => ['expired', 'reverification_required'],
        'rejected' => ['pending'],
        'expired' => ['pending'],
        'reverification_required' => ['pending'],
    ];

    foreach (VerificationStatus::cases() as $from) {
        $allowed = array_map(
            static fn (VerificationStatus $s): string => $s->value,
            $from->allowedTransitions(),
        );

        expect($allowed)->toEqualCanonicalizing($expected[$from->value]);

        foreach (VerificationStatus::cases() as $to) {
            $shouldAllow = $from === $to || in_array($to->value, $expected[$from->value], true);
            expect($from->canTransitionTo($to))->toBe($shouldAllow);
        }
    }
});

it('classifies which rejection reasons a subject can retry', function (): void {
    expect(RejectionReason::DocumentUnreadable->isRetryable())->toBeTrue()
        ->and(RejectionReason::DocumentExpired->isRetryable())->toBeTrue()
        // Nothing the subject can do about these, so the app should not invite
        // them to try again.
        ->and(RejectionReason::SanctionsHit->isRetryable())->toBeFalse()
        ->and(RejectionReason::DuplicateIdentity->isRetryable())->toBeFalse()
        ->and(RejectionReason::UnderageSubject->isRetryable())->toBeFalse();
});

it('ranks verification levels so a stronger level satisfies a weaker requirement', function (): void {
    expect(VerificationLevel::Identity->satisfies(VerificationLevel::Basic))->toBeTrue()
        ->and(VerificationLevel::Identity->satisfies(VerificationLevel::Phone))->toBeTrue()
        ->and(VerificationLevel::Phone->satisfies(VerificationLevel::Identity))->toBeFalse()
        ->and(VerificationLevel::Basic->satisfies(VerificationLevel::Phone))->toBeFalse();
});
