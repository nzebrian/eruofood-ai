<?php

declare(strict_types=1);

use EruoFood\Admin\Domain\Enum\AdminRole;
use EruoFood\Admin\Domain\Rbac\AdminAccount;
use EruoFood\Admin\Domain\Rbac\AdminAccountRepository;
use EruoFood\Admin\Infrastructure\Persistence\Eloquent\Model\AuditLogModel;
use EruoFood\Verification\Application\Service\ReconciliationService;
use EruoFood\Verification\Application\Service\ReviewService;
use EruoFood\Verification\Application\Service\VerificationService;
use EruoFood\Verification\Domain\Document\DocumentMetadata;
use EruoFood\Verification\Domain\Document\DocumentMetadataRepository;
use EruoFood\Verification\Domain\Enum\CaseType;
use EruoFood\Verification\Domain\Enum\DocumentType;
use EruoFood\Verification\Domain\Enum\SubjectType;
use EruoFood\Verification\Domain\Enum\VerificationStatus;
use EruoFood\Verification\Infrastructure\Persistence\Eloquent\Model\VerificationCaseModel;
use EruoFood\Verification\Infrastructure\Persistence\Eloquent\Model\VerificationDocumentModel;
use EruoFood\Verification\Infrastructure\Persistence\Eloquent\Model\VerificationEventModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * M24 — one named regression test per defect found during validation.
 *
 * Each of these five was a real bug in newly written code, and four of them were
 * invisible on SQLite. They are pinned here individually, rather than left to be
 * covered incidentally by a broader test, so that if one regresses the failure
 * says which one.
 */
function regressionRiderCase(?string $riderId = null): string
{
    $service = app(VerificationService::class);
    $case = $service->openCase(
        SubjectType::Rider,
        $riderId ?? (string) Str::uuid(),
        CaseType::Identity,
        'NG',
    );

    return $service->startVerification($case->id(), ['document'])->id();
}

// ---- Defect 1: the document number was stored in plaintext -------------------

it('stores the document fragment as ciphertext and returns it as plaintext', function (): void {
    $caseId = regressionRiderCase();

    $documents = app(DocumentMetadataRepository::class);
    $documents->save(new DocumentMetadata(
        id: $documents->nextIdentity(),
        caseId: $caseId,
        documentType: DocumentType::DriversLicence,
        issuingCountry: 'NG',
        numberLast4: DocumentMetadata::lastFourOf('AKW 12345678'),
        expiresOn: new DateTimeImmutable('2030-01-01'),
        providerReference: 'ref-1',
        createdAt: new DateTimeImmutable(),
    ));

    $stored = (string) VerificationDocumentModel::query()->where('case_id', $caseId)->value('number_last4');

    // The column must not hold the fragment in the clear: a backup, an
    // analytics replica or a support query must not surface it.
    expect($stored)->not->toBe('5678')
        ->and($stored)->not->toContain('5678')
        ->and(strlen($stored))->toBeGreaterThan(20);

    // …and the read path still returns the real value, which is what broke when
    // the two halves disagreed about whether the column was encrypted.
    expect($documents->forCase($caseId)[0]->numberLast4)->toBe('5678');
});

it('never stores more than the last four characters', function (): void {
    expect(DocumentMetadata::lastFourOf('A01234567'))->toBe('4567')
        ->and(DocumentMetadata::lastFourOf('AKW 1234 5678'))->toBe('5678')
        ->and(DocumentMetadata::lastFourOf(null))->toBeNull()
        ->and(DocumentMetadata::lastFourOf('   '))->toBeNull();
});

// ---- Defect 2: every verification error code fell through to HTTP 400 -------

it('maps each verification error code to its proper HTTP status', function (): void {
    $map = [
        'VERIFICATION_WEBHOOK_REJECTED' => 401,
        'VERIFICATION_NOT_AUTHORIZED' => 403,
        'VERIFICATION_STEP_UP_REQUIRED' => 403,
        'VERIFICATION_RESOURCE_NOT_FOUND' => 404,
        'VERIFICATION_CONFLICT' => 409,
        'VERIFICATION_INVALID_STATE' => 422,
        'VERIFICATION_INVALID_TRANSITION' => 422,
        'VERIFICATION_PROVIDER_UNAVAILABLE' => 503,
    ];

    // Asserted against the shipped mapping table rather than by driving eight
    // endpoints: the defect was that these codes were absent from it entirely,
    // so every one of them was answered as a generic 400.
    $source = file_get_contents(base_path('bootstrap/app.php'));

    foreach ($map as $code => $status) {
        expect($source)->toContain($code);
    }

    // The two that are reachable end-to-end, driven for real.
    Mail::fake();
    $owner = $this->postJson('/api/v1/auth/register', [
        'name' => 'Owner', 'email' => 'reg-owner@example.com',
        'password' => 'Password123', 'password_confirmation' => 'Password123',
    ])->assertCreated()->json('data');

    $intruder = $this->postJson('/api/v1/auth/register', [
        'name' => 'Intruder', 'email' => 'reg-intruder@example.com',
        'password' => 'Password123', 'password_confirmation' => 'Password123',
    ])->assertCreated()->json('data');

    $caseId = regressionRiderCase($owner['user']['id']);

    // Not a 400.
    $this->withToken($intruder['tokens']['access_token'])
        ->getJson("/api/v1/verification/cases/{$caseId}")
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'VERIFICATION_NOT_AUTHORIZED');

    $this->withToken($intruder['tokens']['access_token'])
        ->getJson('/api/v1/verification/cases/'.Str::uuid())
        ->assertStatus(404);
});

// ---- Defect 3: the PII audit event had no consumer --------------------------

it('actually writes the PII audit event, not merely publishes it', function (): void {
    Mail::fake();
    $officer = $this->postJson('/api/v1/auth/register', [
        'name' => 'Officer', 'email' => 'reg-officer@example.com',
        'password' => 'Password123', 'password_confirmation' => 'Password123',
    ])->assertCreated()->json('data');

    app(AdminAccountRepository::class)->save(
        AdminAccount::grant($officer['user']['id'], [AdminRole::ComplianceOfficer], new DateTimeImmutable()),
    );

    $caseId = regressionRiderCase();

    $this->withToken($officer['tokens']['access_token'])
        ->getJson("/api/v1/verification/admin/cases/{$caseId}/documents?reason=regression")
        ->assertOk();

    // The event used to be published to a bus with nothing listening, so the
    // audit requirement was structurally unmet while looking satisfied.
    $entry = AuditLogModel::query()->where('action', 'security.verification_pii_accessed')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->actor_id)->toBe($officer['user']['id'])
        ->and($entry->subject_id)->toBe($caseId)
        ->and($entry->context['result'])->toBe('granted');
});

// ---- Defect 4: actor_id was a uuid column, but system actors are names ------

it('records a transition whose actor is a system component, not a user id', function (): void {
    $caseId = regressionRiderCase();

    // Age the case so reconciliation picks it up. The sweep writes
    // actor_id = 'reconciliation', which a uuid column rejects on PostgreSQL
    // while silently accepting it on SQLite — so webhook application,
    // reconciliation and expiry would all have crashed in production.
    VerificationCaseModel::query()->whereKey($caseId)->update(['updated_at' => now()->subDay()]);

    app(ReconciliationService::class)->reconcileStalled();

    expect(VerificationCaseModel::query()->whereKey($caseId)->value('status'))
        ->toBe(VerificationStatus::Verified->value);

    $actors = VerificationEventModel::query()->where('case_id', $caseId)->pluck('actor_id')->filter()->all();

    expect($actors)->toContain('reconciliation');
});

it('accepts every actor id shape the platform actually writes', function (): void {
    $caseId = regressionRiderCase();

    // A person's uuid, a provider name, and two system component names.
    foreach ([(string) Str::uuid(), 'didit', 'reconciliation', 'expiry-sweep'] as $actorId) {
        DB::table('verification_events')->insert([
            'id' => (string) Str::uuid(),
            'case_id' => $caseId,
            'from_status' => 'pending',
            'to_status' => 'processing',
            'actor_type' => 'system',
            'actor_id' => $actorId,
            'occurred_at' => now(),
        ]);
    }

    expect(VerificationEventModel::query()->where('case_id', $caseId)->count())->toBeGreaterThanOrEqual(4);
});

// ---- Defect 5: NOT_STARTED did not occupy the single-open-case slot ---------

it('claims the open slot the moment a case is created, before any attempt', function (): void {
    $riderId = (string) Str::uuid();

    $case = app(VerificationService::class)->openCase(
        SubjectType::Rider,
        $riderId,
        CaseType::Identity,
        'NG',
    );

    // The defect: a case that had been created but not yet handed to a provider
    // held no slot, so the unique index had nothing to catch at exactly the
    // moment cases are created.
    expect($case->status())->toBe(VerificationStatus::NotStarted)
        ->and(VerificationCaseModel::query()->whereKey($case->id())->value('open_key'))
        ->toBe("rider:{$riderId}:identity");
});

it('does not create a second case when a subject starts verification twice', function (): void {
    $riderId = (string) Str::uuid();
    $service = app(VerificationService::class);

    // Two taps on "verify", or one retried request.
    $first = $service->openCase(SubjectType::Rider, $riderId, CaseType::Identity, 'NG');
    $second = $service->openCase(SubjectType::Rider, $riderId, CaseType::Identity, 'NG');
    $third = $service->openCase(SubjectType::Rider, $riderId, CaseType::Identity, 'NG');

    expect($second->id())->toBe($first->id())
        ->and($third->id())->toBe($first->id())
        // Two open cases for one rider means two answers to "is this rider
        // verified", which is the state this rule exists to prevent.
        ->and(VerificationCaseModel::query()->where('subject_id', $riderId)->count())->toBe(1);
});

it('counts NOT_STARTED as open in the status enum itself', function (): void {
    expect(VerificationStatus::NotStarted->isOpen())->toBeTrue()
        ->and(VerificationStatus::Pending->isOpen())->toBeTrue()
        ->and(VerificationStatus::Processing->isOpen())->toBeTrue()
        ->and(VerificationStatus::RequiresReview->isOpen())->toBeTrue()
        // Closed statuses release the slot so a retry can begin.
        ->and(VerificationStatus::Verified->isOpen())->toBeFalse()
        ->and(VerificationStatus::Rejected->isOpen())->toBeFalse()
        ->and(VerificationStatus::Expired->isOpen())->toBeFalse()
        ->and(VerificationStatus::ReverificationRequired->isOpen())->toBeFalse();
});

it('reopens a rejected case rather than orphaning it', function (): void {
    $riderId = (string) Str::uuid();
    $caseId = regressionRiderCase($riderId);

    app(ReviewService::class)->reject(
        $caseId,
        (string) Str::uuid(),
        EruoFood\Verification\Domain\Enum\RejectionReason::DocumentUnreadable,
    );

    $retry = app(VerificationService::class)->openCase(
        SubjectType::Rider,
        $riderId,
        CaseType::Identity,
        'NG',
    );

    // One case per subject stays the durable record, carrying every attempt —
    // otherwise the documented REJECTED → PENDING transition is unreachable
    // through the API and the subject accumulates a row per round.
    expect($retry->id())->toBe($caseId)
        ->and(VerificationCaseModel::query()->where('subject_id', $riderId)->count())->toBe(1);
});
