<?php

declare(strict_types=1);

use EruoFood\Shared\Domain\DataLifecycle\DataCategory;
use EruoFood\Shared\Domain\DataLifecycle\DeletionMode;
use EruoFood\Shared\Domain\DataLifecycle\RetentionPolicy;
use EruoFood\Shared\Domain\DataLifecycle\RetentionRegistry;
use EruoFood\Shared\Domain\Exception\InvalidArgumentException;
use EruoFood\Shared\Domain\Risk\RiskAssessment;
use EruoFood\Shared\Domain\Risk\RiskDecision;
use EruoFood\Shared\Domain\Risk\RiskEvaluator;
use EruoFood\Shared\Domain\Risk\RiskSignalType;
use EruoFood\Shared\Domain\Risk\RiskSubject;
use EruoFood\Shared\Infrastructure\Risk\NullRiskEvaluator;

// ======================================================== data lifecycle

it('refuses to anonymise a category that must stay intact', function (DataCategory $category): void {
    // Anonymising an audit trail destroys the property that makes it worth
    // keeping — it exists precisely to name who acted. Same for a ledger.
    expect(fn () => RetentionPolicy::of(
        'x',
        $category,
        'purpose',
        30,
        DeletionMode::Anonymise,
        'access',
    ))->toThrow(InvalidArgumentException::class, 'cannot anonymise');
})->with([
    DataCategory::AuditTrail,
    DataCategory::FinancialRecord,
    DataCategory::RegulatedIdentity,
    DataCategory::LocationTrail,
]);

it('requires a stated purpose for every retention period', function (): void {
    // A period nobody can justify is a period nobody will ever act on, so the
    // justification is required at construction rather than hoped for.
    expect(fn () => RetentionPolicy::of('x', DataCategory::OperationalRecord, '  ', 30, DeletionMode::Destroy, 'access'))
        ->toThrow(InvalidArgumentException::class, 'purpose');
});

it('gives every declared policy a purpose, an access policy and a mode', function (): void {
    foreach (RetentionRegistry::platformDefaults()->all() as $policy) {
        expect($policy->purpose)->not->toBe('')
            ->and($policy->accessPolicy)->not->toBe('')
            ->and($policy->retainDays)->toBeGreaterThanOrEqual(0);
    }
});

it('keeps location trails on the shortest retention of any personal category', function (): void {
    // The most sensitive routine data the platform holds and the least valuable
    // after the fact. Short retention here is a security control.
    $registry = RetentionRegistry::platformDefaults();
    $locations = $registry->get('geo.rider_locations');
    $identity = $registry->get('verification.identity_documents');

    expect($locations->retainDays)->toBeLessThan($identity->retainDays)
        ->and($locations->deletionMode)->toBe(DeletionMode::Destroy);
});

it('never lets personal data enter telemetry', function (): void {
    // Requirement 14, made checkable rather than merely written down.
    foreach (DataCategory::cases() as $category) {
        expect($category->mayEnterTelemetry())
            ->toBe($category === DataCategory::TransientTechnical);
    }
});

it('refuses erasure only where an obligation outranks the request', function (): void {
    expect(DataCategory::FinancialRecord->honoursErasureRequest())->toBeFalse()
        ->and(DataCategory::RegulatedIdentity->honoursErasureRequest())->toBeFalse()
        ->and(DataCategory::AuditTrail->honoursErasureRequest())->toBeFalse()
        // Everything else is the person's to have removed.
        ->and(DataCategory::LocationTrail->honoursErasureRequest())->toBeTrue()
        ->and(DataCategory::PreferenceRecord->honoursErasureRequest())->toBeTrue();
});

it('marks regulated and financial deletions as audit-requiring whatever the policy says', function (): void {
    $policy = RetentionPolicy::of(
        'x',
        DataCategory::FinancialRecord,
        'p',
        30,
        DeletionMode::Archive,
        'a',
        auditRequired: false,
    );

    expect($policy->requiresAudit())->toBeTrue();
});

it('treats only archival as reversible', function (): void {
    // Why the retention dry-run is not optional: the other two cannot be undone
    // by anybody, at any price.
    foreach (DeletionMode::cases() as $mode) {
        expect($mode->isReversible())->toBe($mode === DeletionMode::Archive);
    }
});

it('keeps the retention purge switched off', function (): void {
    expect(app(EruoFood\Shared\Domain\Flag\FlagEvaluator::class)->isEnabled('lifecycle.retention_purge'))
        ->toBeFalse();
});

it('refuses two policies with the same key', function (): void {
    $registry = new RetentionRegistry();
    $policy = RetentionPolicy::of('dup', DataCategory::OperationalRecord, 'p', 1, DeletionMode::Destroy, 'a');

    $registry->register($policy);

    expect(fn () => $registry->register($policy))->toThrow(InvalidArgumentException::class, 'already registered');
});

// ============================================================== risk seam

it('allows everything through the shipped evaluator', function (): void {
    // A half-built fraud detector is worse than none: people trust its output
    // before it is trustworthy.
    $evaluator = app(RiskEvaluator::class);

    expect($evaluator)->toBeInstanceOf(NullRiskEvaluator::class);

    foreach (RiskSignalType::cases() as $type) {
        $assessment = $evaluator->evaluate(RiskSubject::of(userId: 'u1'), $type);

        expect($assessment->isAllowed())->toBeTrue()
            ->and($assessment->decision)->toBe(RiskDecision::Allow);
    }
});

it('never throws out of an evaluation or an observation', function (): void {
    // A detector that raises into a checkout takes the shop offline.
    $evaluator = app(RiskEvaluator::class);
    $subject = RiskSubject::of(deviceId: 'd1');

    $evaluator->observe($subject, RiskSignalType::FakeAccount, ['count' => 3]);

    expect($evaluator->evaluate($subject, RiskSignalType::FakeAccount)->isAllowed())->toBeTrue();
});

it('downgrades a block to review for signals that may not block', function (): void {
    // Blocking a genuine customer at checkout costs more than reviewing a
    // fraudulent one afterwards, so only the platform-loss signals may refuse.
    $blocked = RiskAssessment::block(RiskSignalType::PaymentAbuse, 'stolen card pattern');
    $downgraded = RiskAssessment::block(RiskSignalType::SuspiciousLocation, 'impossible travel');

    expect($blocked->decision)->toBe(RiskDecision::Block)
        ->and($blocked->isAllowed())->toBeFalse()
        ->and($downgraded->decision)->toBe(RiskDecision::Review)
        ->and($downgraded->isAllowed())->toBeTrue();
});

it('carries identifiers only, never personal data', function (): void {
    // Risk signals flow to analysis and eventually to logs.
    $subject = RiskSubject::of(userId: 'u1', deviceId: 'd1', riderId: 'r1');

    expect(array_keys(get_object_vars($subject)))
        ->toBe(['userId', 'deviceId', 'riderId', 'merchantId', 'orderId', 'ipAddress']);
});

it('knows when there is nothing to assess', function (): void {
    expect(RiskSubject::of()->isIdentifiable())->toBeFalse()
        ->and(RiskSubject::of(ipAddress: '10.0.0.1')->isIdentifiable())->toBeFalse()
        ->and(RiskSubject::of(userId: 'u1')->isIdentifiable())->toBeTrue();
});
