<?php

declare(strict_types=1);

use EruoFood\Verification\Application\Service\VerificationService;
use EruoFood\Verification\Domain\Enum\CaseType;
use EruoFood\Verification\Domain\Enum\SubjectType;
use EruoFood\Verification\Domain\Enum\VerificationStatus;
use EruoFood\Verification\Infrastructure\Persistence\Eloquent\Model\VerificationCaseModel;
use EruoFood\Verification\Infrastructure\Persistence\Eloquent\Model\VerificationEventModel;
use EruoFood\Verification\Infrastructure\Persistence\Eloquent\Model\VerificationWebhookEventModel;
use EruoFood\Verification\Infrastructure\Provider\Mock\MockProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * M24 — the webhook endpoint, which is the platform's most exposed surface.
 *
 * It is unauthenticated by necessity: the provider signs the payload instead. So
 * every property that keeps it safe is asserted here — a forgery cannot move a
 * case, a replay cannot re-apply one, a duplicate applies once, and an unknown
 * session reference creates nothing.
 */
function mockSecret(): string
{
    return (string) config('verification.providers.mock.webhook_secret', 'mock-webhook-secret');
}

/** @return array{caseId: string, reference: string} */
function pendingRiderCase(string $suffix = 'ok'): array
{
    $service = app(VerificationService::class);

    $case = $service->openCase(
        SubjectType::Rider,
        (string) Str::uuid(),
        CaseType::Identity,
        'NG',
    );
    $started = $service->startVerification($case->id(), ['document']);

    return ['caseId' => $started->id(), 'reference' => (string) $started->providerReference()];
}

function webhookBody(string $reference, string $status = 'approved', ?int $timestamp = null, ?string $eventId = null): string
{
    return (string) json_encode([
        'event_id' => $eventId ?? 'evt_'.Str::random(10),
        'reference' => $reference,
        'status' => $status,
        'timestamp' => $timestamp ?? time(),
    ], JSON_THROW_ON_ERROR);
}

function postWebhook(object $test, string $body, ?string $signature = null): Illuminate\Testing\TestResponse
{
    return $test->call(
        'POST',
        '/api/v1/verification/webhooks/mock',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SIGNATURE' => $signature ?? MockProvider::sign($body, mockSecret()),
        ],
        $body,
    );
}

it('applies a correctly signed callback exactly once', function (): void {
    ['caseId' => $caseId, 'reference' => $reference] = pendingRiderCase();
    $body = webhookBody($reference, 'approved', null, 'evt_once');

    postWebhook($this, $body)->assertOk()->assertJson(['received' => true, 'applied' => true]);

    expect(VerificationCaseModel::query()->whereKey($caseId)->value('status'))
        ->toBe(VerificationStatus::Verified->value);

    $eventsAfterFirst = VerificationEventModel::query()->where('case_id', $caseId)->count();

    // Providers retry aggressively; every redelivery must be a no-op.
    foreach (range(1, 4) as $ignored) {
        postWebhook($this, $body)->assertOk()->assertJson(['applied' => false]);
    }

    expect(VerificationEventModel::query()->where('case_id', $caseId)->count())->toBe($eventsAfterFirst)
        ->and(VerificationWebhookEventModel::query()->where('provider_event_id', 'evt_once')->count())->toBe(1);
});

it('rejects a forged signature and changes nothing', function (): void {
    ['caseId' => $caseId, 'reference' => $reference] = pendingRiderCase();

    postWebhook($this, webhookBody($reference), str_repeat('f', 64))
        ->assertStatus(401);

    expect(VerificationCaseModel::query()->whereKey($caseId)->value('status'))
        ->toBe(VerificationStatus::Pending->value)
        ->and(VerificationWebhookEventModel::query()->count())->toBe(0);
});

it('rejects an unsigned callback', function (): void {
    ['reference' => $reference] = pendingRiderCase();

    $this->call(
        'POST',
        '/api/v1/verification/webhooks/mock',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        webhookBody($reference),
    )->assertStatus(401);
});

it('rejects a replayed callback whose timestamp is stale', function (): void {
    ['caseId' => $caseId, 'reference' => $reference] = pendingRiderCase();

    // Correctly signed but captured an hour ago — the shape of a replay attack.
    $body = webhookBody($reference, 'approved', time() - 3600);

    postWebhook($this, $body)->assertStatus(401);

    expect(VerificationCaseModel::query()->whereKey($caseId)->value('status'))
        ->toBe(VerificationStatus::Pending->value);
});

it('rejects a signed callback whose session reference is unknown', function (): void {
    // A valid signature proves the message came from the provider; it does not
    // prove the session belongs to a case we opened.
    postWebhook($this, webhookBody('sess_never_created'))->assertStatus(401);

    expect(VerificationCaseModel::query()->count())->toBe(0);
});

it('reveals nothing about why it refused', function (): void {
    ['reference' => $reference] = pendingRiderCase();

    $forged = postWebhook($this, webhookBody($reference), str_repeat('a', 64));
    $replayed = postWebhook($this, webhookBody($reference, 'approved', time() - 9999));
    $unknown = postWebhook($this, webhookBody('sess_nope'));

    // An endpoint that explains why it rejected a forgery is a tool for
    // refining the forgery: all three answers are identical.
    expect($forged->status())->toBe(401)
        ->and($replayed->status())->toBe(401)
        ->and($unknown->status())->toBe(401)
        ->and($forged->json())->toEqual($replayed->json())
        ->and($replayed->json())->toEqual($unknown->json())
        ->and($forged->json('error.code'))->toBe('UNAUTHORIZED');
});

it('never writes the payload to the application log', function (): void {
    ['reference' => $reference] = pendingRiderCase();

    $sensitive = (string) json_encode([
        'event_id' => 'evt_leak',
        'reference' => $reference,
        'status' => 'approved',
        'timestamp' => time(),
        'full_name' => 'Ada Lovelace',
        'document_number' => 'A01234567',
    ], JSON_THROW_ON_ERROR);

    $records = [];
    Illuminate\Support\Facades\Log::listen(function ($message) use (&$records): void {
        $records[] = json_encode([$message->message, $message->context]);
    });

    // Forged, so it takes the logging path a rejection follows.
    postWebhook($this, $sensitive, str_repeat('b', 64))->assertStatus(401);

    $logged = implode("\n", $records);

    expect($logged)->not->toContain('Ada Lovelace')
        ->and($logged)->not->toContain('A01234567')
        // A digest is kept so repeat forgeries can be correlated without the
        // payload itself ever reaching the log.
        ->and($logged)->toContain('body_sha256');
});

it('records which signature scheme proved the payload', function (): void {
    ['reference' => $reference] = pendingRiderCase();

    postWebhook($this, webhookBody($reference, 'approved', null, 'evt_scheme'))->assertOk();

    expect(VerificationWebhookEventModel::query()->where('provider_event_id', 'evt_scheme')->value('signature_scheme'))
        ->toBe('mock');
});

it('routes a declined callback to a rejection with a reason', function (): void {
    ['caseId' => $caseId, 'reference' => $reference] = pendingRiderCase();

    postWebhook($this, webhookBody($reference, 'declined'))->assertOk();

    $row = VerificationCaseModel::query()->whereKey($caseId)->first();

    expect($row->status)->toBe(VerificationStatus::Rejected->value)
        ->and($row->decision_reason_code)->toBe('document_unreadable');
});

it('routes an in-review callback to the queue rather than a verdict', function (): void {
    ['caseId' => $caseId, 'reference' => $reference] = pendingRiderCase();

    postWebhook($this, webhookBody($reference, 'in_review'))->assertOk();

    expect(VerificationCaseModel::query()->whereKey($caseId)->value('status'))
        ->toBe(VerificationStatus::RequiresReview->value);
});

it('rejects a callback naming a provider that does not exist', function (): void {
    $body = webhookBody('anything');

    $this->call(
        'POST',
        '/api/v1/verification/webhooks/nonsense',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGNATURE' => MockProvider::sign($body, mockSecret())],
        $body,
    )->assertStatus(401);
});
