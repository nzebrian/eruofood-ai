<?php

declare(strict_types=1);

use EruoFood\Verification\Application\Service\ReconciliationService;
use EruoFood\Verification\Application\Service\ReviewService;
use EruoFood\Verification\Application\Service\VerificationService;
use EruoFood\Verification\Domain\Enum\ActorType;
use EruoFood\Verification\Domain\Enum\CaseType;
use EruoFood\Verification\Domain\Enum\SubjectType;
use EruoFood\Verification\Domain\Enum\VerificationStatus;
use EruoFood\Verification\Domain\VerificationCase\CaseRepository;
use EruoFood\Verification\Infrastructure\Persistence\Eloquent\Model\VerificationAttemptModel;
use EruoFood\Verification\Infrastructure\Persistence\Eloquent\Model\VerificationCaseModel;
use EruoFood\Verification\Infrastructure\Persistence\Eloquent\Model\VerificationEventModel;
use EruoFood\Verification\Infrastructure\Persistence\Eloquent\Model\VerificationWebhookEventModel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * M24 — the guarantees that live in the schema rather than in code.
 *
 * Application checks are the first line, but they run in one process against a
 * value read a moment ago. The properties asserted here hold even when two
 * requests race or a future caller forgets: one open case per subject, one
 * attempt per provider reference, one application per webhook event, and an
 * audit trail nobody can edit.
 *
 * The append-only trigger is a PostgreSQL object with no SQLite equivalent, so
 * those tests declare that plainly and skip rather than asserting a protection
 * the test engine does not actually provide.
 */
function pgOnly(): bool
{
    return DB::connection()->getDriverName() === 'pgsql';
}

function openRiderCase(?string $subjectId = null): string
{
    return app(VerificationService::class)->openCase(
        SubjectType::Rider,
        $subjectId ?? (string) Str::uuid(),
        CaseType::Identity,
        'NG',
    )->id();
}

// ------------------------------------------------------- one case per subject --

it('refuses a second open case for the same subject and case type', function (): void {
    $riderId = (string) Str::uuid();
    $service = app(VerificationService::class);

    $first = $service->openCase(SubjectType::Rider, $riderId, CaseType::Identity, 'NG');

    // The service hands back the existing case rather than opening a rival one:
    // two open cases for one rider means two answers to "is this rider verified".
    $second = $service->openCase(SubjectType::Rider, $riderId, CaseType::Identity, 'NG');

    expect($second->id())->toBe($first->id())
        ->and(VerificationCaseModel::query()->where('subject_id', $riderId)->count())->toBe(1);
});

it('enforces the single-open-case rule in the database, not just in the service', function (): void {
    $riderId = (string) Str::uuid();
    $caseId = openRiderCase($riderId);

    $openKey = VerificationCaseModel::query()->whereKey($caseId)->value('open_key');
    expect($openKey)->toBe("rider:{$riderId}:identity");

    // A second row claiming the same slot — what a racing request would try to
    // write — is refused by the unique index regardless of what the service did.
    expect(fn () => DB::table('verification_cases')->insert([
        'id' => (string) Str::uuid(),
        'subject_type' => 'rider',
        'subject_id' => $riderId,
        'case_type' => 'identity',
        'country_code' => 'NG',
        'status' => 'not_started',
        'required_level' => 'identity',
        'open_key' => $openKey,
        'version' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('frees the slot once a case closes so a reverification can open a new one', function (): void {
    $riderId = (string) Str::uuid();
    $caseId = openRiderCase($riderId);

    $service = app(VerificationService::class);
    $service->startVerification($caseId, ['document']);
    app(ReviewService::class)->approve($caseId, 'admin-1');

    // Closed cases release the open slot — a nullable-unique column rather than
    // a partial index, so the same rule holds on both engines.
    expect(VerificationCaseModel::query()->whereKey($caseId)->value('open_key'))->toBeNull();

    app(ReviewService::class)->requireReverification($caseId, 'admin-1');
    $reopened = $service->openCase(SubjectType::Rider, $riderId, CaseType::Identity, 'NG');

    expect($reopened->id())->toBe($caseId);
});

it('lets one subject hold an identity case and a business case at once', function (): void {
    $subjectId = (string) Str::uuid();
    $service = app(VerificationService::class);

    $identity = $service->openCase(SubjectType::Business, $subjectId, CaseType::Identity, 'NG');
    $business = $service->openCase(SubjectType::Business, $subjectId, CaseType::Business, 'NG');

    // The slot is keyed by case type too: a merchant's KYB and its
    // representative's identity check are different questions.
    expect($identity->id())->not->toBe($business->id())
        ->and(VerificationCaseModel::query()->where('subject_id', $subjectId)->count())->toBe(2);
});

// --------------------------------------------------------- provider references --

it('refuses two attempts sharing one provider reference', function (): void {
    $caseId = openRiderCase();
    app(VerificationService::class)->startVerification($caseId, ['document']);

    $reference = (string) VerificationAttemptModel::query()->where('case_id', $caseId)->value('provider_reference');

    // A provider session maps to exactly one attempt, which is what makes a
    // webhook's session id an unambiguous address.
    expect(fn () => DB::table('verification_attempts')->insert([
        'id' => (string) Str::uuid(),
        'case_id' => $caseId,
        'provider' => 'mock',
        'provider_reference' => $reference,
        'status' => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('refuses two webhook rows for one provider event', function (): void {
    DB::table('verification_webhook_events')->insert([
        'id' => (string) Str::uuid(),
        'provider' => 'mock',
        'provider_event_id' => 'evt_dupe',
        'signature_scheme' => 'mock',
        'received_at' => now(),
    ]);

    // The claim-first uniqueness that makes webhook application exactly-once.
    expect(fn () => DB::table('verification_webhook_events')->insert([
        'id' => (string) Str::uuid(),
        'provider' => 'mock',
        'provider_event_id' => 'evt_dupe',
        'signature_scheme' => 'mock',
        'received_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('lets two providers use the same event id', function (): void {
    foreach (['mock', 'didit'] as $provider) {
        DB::table('verification_webhook_events')->insert([
            'id' => (string) Str::uuid(),
            'provider' => $provider,
            'provider_event_id' => 'evt_shared',
            'signature_scheme' => $provider,
            'received_at' => now(),
        ]);
    }

    // Uniqueness is per provider: event ids are the provider's namespace, and
    // a global constraint would let one provider block another's callback.
    expect(VerificationWebhookEventModel::query()->where('provider_event_id', 'evt_shared')->count())->toBe(2);
});

// ------------------------------------------------------------ optimistic version --

it('refuses a write built on a stale read', function (): void {
    $caseId = openRiderCase();
    $cases = app(CaseRepository::class);

    $stale = $cases->findById($caseId);

    // Somebody else moves the case in between.
    app(VerificationService::class)->startVerification($caseId, ['document']);

    $stale->startAttempt(
        EruoFood\Verification\Domain\Enum\ProviderName::Mock,
        'ref-stale',
        ActorType::Subject,
        'u1',
        new DateTimeImmutable(),
    );

    // The version guard is what stops a reviewer's decision and an arriving
    // webhook from each overwriting the other's verdict.
    expect(fn () => $cases->save($stale))
        ->toThrow(EruoFood\Shared\Domain\Exception\ConcurrencyConflict::class);
});

// ------------------------------------------------------------- the audit trail --

it('keeps a status change for every transition', function (): void {
    $caseId = openRiderCase();
    app(VerificationService::class)->startVerification($caseId, ['document']);
    app(ReviewService::class)->approve($caseId, 'admin-7');

    $events = VerificationEventModel::query()->where('case_id', $caseId)->orderBy('occurred_at')->get();

    expect($events)->toHaveCount(3)
        ->and($events->pluck('to_status')->all())
        ->toBe(['not_started', 'pending', 'verified'])
        // Who decided, not merely that it was decided.
        ->and($events->last()->actor_id)->toBe('admin-7')
        ->and($events->last()->actor_type)->toBe('admin');
});

it('will not let anyone edit the verification trail', function (): void {
    $caseId = openRiderCase();

    expect(fn () => DB::table('verification_events')->where('case_id', $caseId)->update(['to_status' => 'verified']))
        ->toThrow(QueryException::class);
})->skip(fn (): bool => ! pgOnly(), 'Append-only triggers are a PostgreSQL object; verified on the production engine.');

it('will not let anyone delete the verification trail', function (): void {
    $caseId = openRiderCase();

    expect(fn () => DB::table('verification_events')->where('case_id', $caseId)->delete())
        ->toThrow(QueryException::class);
})->skip(fn (): bool => ! pgOnly(), 'Append-only triggers are a PostgreSQL object; verified on the production engine.');

it('will not let a privileged reader erase the record that they looked', function (): void {
    DB::table('admin_audit_log')->insert([
        'id' => (string) Str::uuid(),
        'actor_id' => (string) Str::uuid(),
        'category' => 'security',
        'action' => 'security.verification_pii_accessed',
        'context' => json_encode(['permission' => 'verification.pii']),
        'created_at' => now(),
    ]);

    // The whole point of auditing privileged access: an actor who can delete
    // the evidence has not been audited at all.
    expect(fn () => DB::table('admin_audit_log')->where('category', 'security')->delete())
        ->toThrow(QueryException::class);
})->skip(fn (): bool => ! pgOnly(), 'Append-only triggers are a PostgreSQL object; verified on the production engine.');

// ------------------------------------------------------------- reconciliation --

it('recovers a case whose webhook never arrived', function (): void {
    $caseId = openRiderCase();
    app(VerificationService::class)->startVerification($caseId, ['document']);

    // The provider decided; the callback was lost. Age the attempt past the
    // reconciliation window so the sweep picks it up.
    VerificationCaseModel::query()->whereKey($caseId)->update(['updated_at' => now()->subDay()]);

    $result = app(ReconciliationService::class)->reconcileStalled();

    expect($result['checked'])->toBe(1)
        ->and($result['updated'])->toBe(1)
        // A reconciled result is interpreted by the same code a pushed one is,
        // so the two cannot drift.
        ->and(VerificationCaseModel::query()->whereKey($caseId)->value('status'))
        ->toBe(VerificationStatus::Verified->value);
});

it('leaves a fresh case alone', function (): void {
    $caseId = openRiderCase();
    app(VerificationService::class)->startVerification($caseId, ['document']);

    // Still inside the window: polling every in-flight case immediately would
    // hammer the provider and defeat the webhook.
    expect(app(ReconciliationService::class)->reconcileStalled()['checked'])->toBe(0)
        ->and(VerificationCaseModel::query()->whereKey($caseId)->value('status'))
        ->toBe(VerificationStatus::Pending->value);
});

it('counts a provider outage as a failure rather than a decision', function (): void {
    $caseId = openRiderCase();
    app(VerificationService::class)->startVerification($caseId, ['document']);
    VerificationCaseModel::query()->whereKey($caseId)->update(['updated_at' => now()->subDay()]);

    $registry = Mockery::mock(EruoFood\Verification\Application\Port\VerificationProviderRegistry::class);
    $registry->shouldReceive('for')->andThrow(new RuntimeException('provider unreachable'));
    app()->instance(EruoFood\Verification\Application\Port\VerificationProviderRegistry::class, $registry);
    app()->forgetInstance(ReconciliationService::class);

    $result = app(ReconciliationService::class)->reconcileStalled();

    // An unreachable provider must never be read as a verdict in either
    // direction — the case stays exactly where it was.
    expect($result['failed'])->toBe(1)
        ->and($result['updated'])->toBe(0)
        ->and(VerificationCaseModel::query()->whereKey($caseId)->value('status'))
        ->toBe(VerificationStatus::Pending->value);
});

it('expires a verification whose validity has run out', function (): void {
    $caseId = openRiderCase();
    app(VerificationService::class)->startVerification($caseId, ['document']);
    app(ReviewService::class)->approve($caseId, 'admin-1');

    VerificationCaseModel::query()->whereKey($caseId)->update(['expires_at' => now()->subDay()]);

    expect(app(ReconciliationService::class)->expireLapsed()['expired'])->toBe(1)
        ->and(VerificationCaseModel::query()->whereKey($caseId)->value('status'))
        ->toBe(VerificationStatus::Expired->value);
});

it('leaves a verification inside its validity alone', function (): void {
    $caseId = openRiderCase();
    app(VerificationService::class)->startVerification($caseId, ['document']);
    app(ReviewService::class)->approve($caseId, 'admin-1');

    VerificationCaseModel::query()->whereKey($caseId)->update(['expires_at' => now()->addYear()]);

    expect(app(ReconciliationService::class)->expireLapsed()['expired'])->toBe(0)
        ->and(VerificationCaseModel::query()->whereKey($caseId)->value('status'))
        ->toBe(VerificationStatus::Verified->value);
});

// ------------------------------------------------------------------- retention --

it('keeps no document image, path or blob anywhere', function (): void {
    $columns = DB::getSchemaBuilder()->getColumnListing('verification_documents');

    // The platform holds a reference to the provider's session, never the
    // artefact. Asserted against the schema so it cannot be reintroduced
    // quietly by a later migration.
    foreach (['image', 'file', 'path', 'blob', 'content', 'document_data', 'url'] as $forbidden) {
        expect(implode(',', $columns))->not->toContain($forbidden);
    }
});
