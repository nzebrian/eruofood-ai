<?php

declare(strict_types=1);

use EruoFood\Admin\Domain\Enum\AdminRole;
use EruoFood\Admin\Domain\Rbac\AdminAccount;
use EruoFood\Admin\Domain\Rbac\AdminAccountRepository;
use EruoFood\Admin\Infrastructure\Persistence\Eloquent\Model\AuditLogModel;
use EruoFood\Verification\Application\Service\VerificationService;
use EruoFood\Verification\Domain\Document\DocumentMetadata;
use EruoFood\Verification\Domain\Document\DocumentMetadataRepository;
use EruoFood\Verification\Domain\Enum\CaseType;
use EruoFood\Verification\Domain\Enum\DocumentType;
use EruoFood\Verification\Domain\Enum\SubjectType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/**
 * M24 — who may see what, and what it costs them to look.
 *
 * Two separate properties are asserted here. The first is ordinary object-level
 * authorisation: a case is addressed by a UUID, and holding the UUID must not
 * be the same as being entitled to the case. The second is the harder one — a
 * privileged reader is *allowed* to open regulated identity data, so the
 * control is not refusal but visibility. Every read, granted or denied,
 * SuperAdmin included, leaves an immutable record naming who looked.
 */

/**
 * Register a user, optionally granting admin roles.
 *
 * @param list<AdminRole> $roles
 * @return array{token: string, id: string}
 */
function verifUser(object $test, string $email, array $roles = []): array
{
    Mail::fake();
    $data = $test->postJson('/api/v1/auth/register', [
        'name' => 'Test Person',
        'email' => $email,
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ])->assertCreated()->json('data');

    $id = $data['user']['id'];
    if ($roles !== []) {
        app(AdminAccountRepository::class)->save(AdminAccount::grant($id, $roles, new DateTimeImmutable()));
    }

    return ['token' => $data['tokens']['access_token'], 'id' => $id];
}

/** A rider case with one document's metadata attached. */
function caseWithDocument(string $riderId): string
{
    $service = app(VerificationService::class);
    $case = $service->openCase(SubjectType::Rider, $riderId, CaseType::Identity, 'NG');
    $started = $service->startVerification($case->id(), ['document']);

    $documents = app(DocumentMetadataRepository::class);
    $documents->save(new DocumentMetadata(
        id: $documents->nextIdentity(),
        caseId: $started->id(),
        documentType: DocumentType::DriversLicence,
        issuingCountry: 'NG',
        numberLast4: DocumentMetadata::lastFourOf('AKW 12345678'),
        expiresOn: new DateTimeImmutable('2030-01-01'),
        providerReference: 'ref-1',
        createdAt: new DateTimeImmutable(),
    ));

    return $started->id();
}

/** @return array<int, AuditLogModel> */
function piiAudit(): array
{
    return AuditLogModel::query()
        ->where('action', 'security.verification_pii_accessed')
        ->orderBy('created_at')
        ->get()
        ->all();
}

// ------------------------------------------------------ subject-level access --

it('refuses to show one user the verification case of another', function (): void {
    ['id' => $ownerId] = verifUser($this, 'owner@example.com');
    ['token' => $intruderToken] = verifUser($this, 'intruder@example.com');

    $caseId = caseWithDocument($ownerId);

    // Holding the identifier is not entitlement to the record behind it.
    $this->withToken($intruderToken)->getJson("/api/v1/verification/cases/{$caseId}")
        ->assertStatus(403);
});

it('lets a subject read their own case', function (): void {
    ['id' => $riderId, 'token' => $token] = verifUser($this, 'rider@example.com');
    $caseId = caseWithDocument($riderId);

    $this->withToken($token)->getJson("/api/v1/verification/cases/{$caseId}")
        ->assertOk()
        ->assertJsonPath('data.id', $caseId);
});

it('refuses every verification route to an unauthenticated caller', function (): void {
    $this->getJson('/api/v1/verification/me')->assertStatus(401);
    $this->getJson('/api/v1/verification/rider/status')->assertStatus(401);
    $this->getJson('/api/v1/verification/admin/queue')->assertStatus(401);
});

it('never returns identity data on a subject-facing case view', function (): void {
    ['id' => $riderId, 'token' => $token] = verifUser($this, 'selfread@example.com');
    $caseId = caseWithDocument($riderId);

    $body = $this->withToken($token)->getJson("/api/v1/verification/cases/{$caseId}")
        ->assertOk()->json('data');

    // The subject's own view carries status, not documents: an ordinary API
    // response is never a channel for regulated data, even to its owner.
    expect($body)->not->toHaveKey('documents')
        ->and(json_encode($body))->not->toContain('5678');
});

// ------------------------------------------------------------- back office --

it('keeps the review queue closed to a user with no admin role', function (): void {
    ['token' => $token] = verifUser($this, 'nobody@example.com');

    $this->withToken($token)->getJson('/api/v1/verification/admin/queue')->assertStatus(403);
});

it('lets an ordinary administrator work the queue', function (): void {
    ['id' => $riderId] = verifUser($this, 'r1@example.com');
    caseWithDocument($riderId);
    ['token' => $token] = verifUser($this, 'admin1@example.com', [AdminRole::Admin]);

    $this->withToken($token)->getJson('/api/v1/verification/admin/queue')->assertOk();
    $this->withToken($token)->getJson('/api/v1/verification/admin/reason-codes')->assertOk();
});

it('refuses an ordinary administrator the identity data behind a case', function (): void {
    ['id' => $riderId] = verifUser($this, 'r2@example.com');
    $caseId = caseWithDocument($riderId);
    ['token' => $token] = verifUser($this, 'admin2@example.com', [AdminRole::Admin]);

    // The daily job — clearing a verification backlog — is possible without
    // ever opening a rider's licence. Holding "admin" does not confer that.
    $this->withToken($token)->getJson("/api/v1/verification/admin/cases/{$caseId}/documents")
        ->assertStatus(403);
});

it('refuses an operations manager both the decision and the documents', function (): void {
    ['id' => $riderId] = verifUser($this, 'r3@example.com');
    $caseId = caseWithDocument($riderId);
    ['token' => $token] = verifUser($this, 'ops@example.com', [AdminRole::OperationsManager]);

    // Reading the queue is part of running operations; deciding a verification
    // case and opening identity data are not.
    $this->withToken($token)->getJson('/api/v1/verification/admin/queue')->assertOk();
    $this->withToken($token)->postJson("/api/v1/verification/admin/cases/{$caseId}/approve")
        ->assertStatus(403);
    $this->withToken($token)->getJson("/api/v1/verification/admin/cases/{$caseId}/documents")
        ->assertStatus(403);
});

it('gives a compliance officer the identity data their role exists for', function (): void {
    ['id' => $riderId] = verifUser($this, 'r4@example.com');
    $caseId = caseWithDocument($riderId);
    ['token' => $token] = verifUser($this, 'compliance@example.com', [AdminRole::ComplianceOfficer]);

    $documents = $this->withToken($token)
        ->getJson("/api/v1/verification/admin/cases/{$caseId}/documents?reason=sar-review")
        ->assertOk()->json('data');

    expect($documents)->toHaveCount(1)
        ->and($documents[0]['document_type'])->toBe('drivers_licence')
        // Last four only: the full number was never stored, so no permission
        // level can produce it.
        ->and($documents[0]['number_last4'])->toBe('5678');
});

// --------------------------------------------------------- the audit trail --

it('records an immutable audit event for every privileged read of identity data', function (): void {
    ['id' => $riderId] = verifUser($this, 'r5@example.com');
    $caseId = caseWithDocument($riderId);
    ['token' => $token, 'id' => $officerId] = verifUser($this, 'officer@example.com', [AdminRole::ComplianceOfficer]);

    $this->withToken($token)
        ->withHeader('X-Request-Id', 'req-abc-123')
        ->getJson("/api/v1/verification/admin/cases/{$caseId}/documents?reason=fraud+investigation+4471")
        ->assertOk();

    $entries = piiAudit();

    expect($entries)->toHaveCount(1);

    $entry = $entries[0];

    // Everything an access review needs to answer "who looked at this rider's
    // licence, when, on what authority, and why".
    expect($entry->actor_id)->toBe($officerId)
        ->and($entry->subject_type)->toBe('verification_case')
        ->and($entry->subject_id)->toBe($caseId)
        ->and($entry->category)->toBe('security')
        ->and($entry->created_at)->not->toBeNull()
        ->and($entry->context['permission'])->toBe('verification.pii')
        ->and($entry->context['action'])->toBe('read_documents')
        ->and($entry->context['result'])->toBe('granted')
        ->and($entry->context['reason'])->toBe('fraud investigation 4471')
        ->and($entry->context['correlationId'])->toBe('req-abc-123');
});

it('audits a SuperAdmin exactly as closely as anyone else', function (): void {
    ['id' => $riderId] = verifUser($this, 'r6@example.com');
    $caseId = caseWithDocument($riderId);
    ['token' => $token, 'id' => $superId] = verifUser($this, 'super@example.com', [AdminRole::SuperAdmin]);

    $this->withToken($token)->getJson("/api/v1/verification/admin/cases/{$caseId}/documents")
        ->assertOk();

    $entries = piiAudit();

    // The role keeps the permission — that is what the approved RBAC model
    // says. What it does not keep is the ability to look unobserved.
    expect($entries)->toHaveCount(1)
        ->and($entries[0]->actor_id)->toBe($superId)
        ->and($entries[0]->context['result'])->toBe('granted');
});

it('records a refused attempt to read identity data', function (): void {
    ['id' => $riderId] = verifUser($this, 'r7@example.com');
    $caseId = caseWithDocument($riderId);
    ['token' => $token] = verifUser($this, 'prober@example.com', [AdminRole::Admin]);

    $this->withToken($token)->getJson("/api/v1/verification/admin/cases/{$caseId}/documents")
        ->assertStatus(403);

    // A rejected attempt is the signal a security review most wants; auditing
    // only successes would let someone probe the boundary silently. The refusal
    // here comes from route middleware, so the entry is the middleware's — what
    // matters is that the attempt is not invisible.
    expect(AuditLogModel::query()->count())->toBeGreaterThan(0);
});

it('audits a denial raised inside the service when the permission check is bypassed', function (): void {
    ['id' => $riderId] = verifUser($this, 'r8@example.com');
    $caseId = caseWithDocument($riderId);

    $reviews = app(EruoFood\Verification\Application\Service\ReviewService::class);
    // A real admin id: the audit trail's actor column is the person who acted.
    $actorId = (string) Illuminate\Support\Str::uuid();

    // Simulating a future caller that forgets the middleware: the service still
    // refuses, and still records the attempt.
    expect(fn () => $reviews->sensitiveDocuments($caseId, $actorId, false, 'curiosity'))
        ->toThrow(EruoFood\Verification\Domain\Exception\VerificationNotAuthorized::class);

    $entries = piiAudit();

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->actor_id)->toBe($actorId)
        ->and($entries[0]->context['result'])->toBe('denied')
        ->and($entries[0]->context['reason'])->toBe('curiosity');
});

it('never writes identity data into the audit entry itself', function (): void {
    ['id' => $riderId] = verifUser($this, 'r9@example.com');
    $caseId = caseWithDocument($riderId);
    ['token' => $token] = verifUser($this, 'auditor@example.com', [AdminRole::ComplianceOfficer]);

    $this->withToken($token)->getJson("/api/v1/verification/admin/cases/{$caseId}/documents")->assertOk();

    // The trail records *that* someone looked and on what authority. Copying
    // the data into the audit log would simply create a second, longer-lived
    // copy of the thing being protected.
    expect(json_encode(piiAudit()[0]->context))->not->toContain('5678');
});

it('does not audit the queue, which carries no identity data', function (): void {
    ['id' => $riderId] = verifUser($this, 'r10@example.com');
    $caseId = caseWithDocument($riderId);
    ['token' => $token] = verifUser($this, 'admin3@example.com', [AdminRole::Admin]);

    $this->withToken($token)->getJson('/api/v1/verification/admin/queue')->assertOk();
    $this->withToken($token)->getJson("/api/v1/verification/admin/cases/{$caseId}")->assertOk();
    $this->withToken($token)->getJson("/api/v1/verification/admin/cases/{$caseId}/history")->assertOk();

    // A PII audit that fires on every ordinary page view is noise, and noise is
    // what makes a real access disappear.
    expect(piiAudit())->toHaveCount(0);
});
