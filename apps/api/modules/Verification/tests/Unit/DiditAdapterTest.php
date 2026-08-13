<?php

declare(strict_types=1);

use EruoFood\Verification\Application\DTO\VerificationRequest;
use EruoFood\Verification\Application\DTO\WebhookHeaders;
use EruoFood\Verification\Domain\Enum\CaseType;
use EruoFood\Verification\Domain\Enum\DocumentType;
use EruoFood\Verification\Domain\Enum\ProviderName;
use EruoFood\Verification\Domain\Enum\RejectionReason;
use EruoFood\Verification\Domain\Enum\SubjectType;
use EruoFood\Verification\Domain\Enum\VerificationStatus;
use EruoFood\Verification\Domain\Exception\ProviderUnavailable;
use EruoFood\Verification\Domain\Exception\WebhookRejected;
use EruoFood\Verification\Infrastructure\Provider\Didit\DiditProvider;
use EruoFood\Verification\Infrastructure\Provider\Didit\DiditStatusMap;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * M24 — the Didit adapter, against the contract published in Didit's own
 * reference implementation.
 *
 * These tests are the guard on the one file that knows how Didit works. They
 * pin the request shape, the status vocabulary and — most importantly — the
 * three signature schemes, because an error in any of those either rejects every
 * genuine callback or accepts a forged one.
 */
const DIDIT_SECRET = 'test-webhook-secret';

function diditConfig(array $overrides = []): array
{
    return array_replace([
        'enabled' => true,
        'base_url' => 'https://verification.didit.me',
        'api_key' => 'test-api-key',
        'webhook_secret' => DIDIT_SECRET,
        'timeout' => 5,
        'retry_attempts' => 1,
        'retry_delay_ms' => 0,
        'replay_tolerance' => 300,
        'workflows' => [
            'rider_identity' => 'wf-rider',
            'rider_licence' => 'wf-licence',
            'representative_identity' => 'wf-rep',
            'customer_identity' => 'wf-customer',
            'business' => 'wf-business',
        ],
        'callback_url' => 'https://api.eruofood.ai/api/v1/verification/webhooks/didit',
    ], $overrides);
}

function diditProvider(HttpFactory $http, array $overrides = []): DiditProvider
{
    return new DiditProvider($http, diditConfig($overrides));
}

function diditRequest(array $checks = ['document'], SubjectType $subject = SubjectType::Rider): VerificationRequest
{
    return new VerificationRequest(
        caseId: '11111111-2222-3333-4444-555555555555',
        subjectType: $subject,
        caseType: CaseType::Identity,
        countryCode: 'NG',
        requiredChecks: $checks,
    );
}

/** Canonical JSON exactly as Didit's V2 scheme signs it. */
function diditV2Signature(array $payload, string $secret = DIDIT_SECRET): string
{
    ksort($payload);
    $parts = [];
    foreach ($payload as $key => $value) {
        $parts[] = json_encode((string) $key).':'.json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    return hash_hmac('sha256', '{'.implode(',', $parts).'}', $secret);
}

function diditSimpleSignature(array $payload, string $secret = DIDIT_SECRET): string
{
    $canonical = implode(':', [
        (string) ($payload['timestamp'] ?? ''),
        (string) ($payload['session_id'] ?? ''),
        (string) ($payload['status'] ?? ''),
        (string) ($payload['webhook_type'] ?? ''),
    ]);

    return hash_hmac('sha256', $canonical, $secret);
}

// ---------------------------------------------------------------- sessions --

it('creates a session with the documented request shape', function (): void {
    $http = new HttpFactory();
    $http->fake([
        '*/v3/session/' => $http->response([
            'session_id' => 'sess_abc123',
            'url' => 'https://verify.didit.me/s/abc123',
            'status' => 'Not Started',
        ], 201),
    ]);

    $session = diditProvider($http)->createSession(diditRequest());

    expect($session->provider)->toBe(ProviderName::Didit)
        ->and($session->providerReference)->toBe('sess_abc123')
        ->and($session->hostedUrl)->toBe('https://verify.didit.me/s/abc123')
        ->and($session->status)->toBe(VerificationStatus::Pending);

    $http->assertSent(function ($request): bool {
        $body = $request->data();

        return $request->hasHeader('X-API-Key', 'test-api-key')
            && str_ends_with($request->url(), '/v3/session/')
            && $body['workflow_id'] === 'wf-rider'
            // The case id, never the user id — nothing about our identity model
            // leaves the platform.
            && $body['vendor_data'] === '11111111-2222-3333-4444-555555555555'
            && isset($body['callback']);
    });
});

it('reads the hosted url from either field name Didit publishes', function (): void {
    $http = new HttpFactory();
    $http->fake([
        '*/v3/session/' => $http->response([
            'session_id' => 'sess_x',
            // Their API reference spells this session_url; their demo types say
            // url. The adapter accepts both rather than betting on one.
            'session_url' => 'https://verify.didit.me/s/x',
            'status' => 'Not Started',
        ], 201),
    ]);

    expect(diditProvider($http)->createSession(diditRequest())->hostedUrl)
        ->toBe('https://verify.didit.me/s/x');
});

it('selects the licence workflow only when a driving licence is required', function (): void {
    $http = new HttpFactory();
    $http->fake(['*' => $http->response(['session_id' => 's', 'url' => 'u', 'status' => 'Not Started'], 201)]);

    diditProvider($http)->createSession(diditRequest(['document', 'driving_licence']));
    $http->assertSent(fn ($request): bool => $request->data()['workflow_id'] === 'wf-licence');
});

it('fails loudly rather than silently when the provider rejects the session', function (): void {
    $http = new HttpFactory();
    $http->fake(['*' => $http->response(['detail' => 'bad workflow'], 422)]);

    expect(fn () => diditProvider($http)->createSession(diditRequest()))
        ->toThrow(ProviderUnavailable::class);
});

it('refuses to create a session when no workflow is configured', function (): void {
    $http = new HttpFactory();
    $http->fake(['*' => $http->response([], 201)]);

    $provider = diditProvider($http, ['workflows' => []]);

    expect(fn () => $provider->createSession(diditRequest()))->toThrow(ProviderUnavailable::class);
});

it('fetches a decision and reduces the document to the facts we keep', function (): void {
    $http = new HttpFactory();
    $http->fake([
        '*/decision/' => $http->response([
            'session_id' => 'sess_abc123',
            'status' => 'Approved',
            'kyc' => [
                'status' => 'Approved',
                'document_type' => 'Driving Licence',
                'document_number' => 'AB123456789',
                'expiration_date' => '2031-04-01',
                'issuing_state' => 'NG',
                // Present in the payload, deliberately not carried forward.
                'full_name' => 'Ada Lovelace',
                'date_of_birth' => '1990-01-01',
            ],
        ], 200),
    ]);

    $decision = diditProvider($http)->fetchDecision('sess_abc123');

    expect($decision->status)->toBe(VerificationStatus::Verified)
        ->and($decision->documents)->toHaveCount(1)
        ->and($decision->documents[0]->type)->toBe(DocumentType::DriversLicence)
        ->and($decision->documents[0]->expiresOn)->toBe('2031-04-01');

    // The name and date of birth stop at the adapter boundary.
    $encoded = json_encode($decision);
    expect($encoded)->not->toContain('Ada Lovelace')
        ->and($encoded)->not->toContain('1990-01-01');
});

// ----------------------------------------------------------- status mapping --

it('maps every published Didit status, in either spelling', function (): void {
    $cases = [
        'Not Started' => VerificationStatus::Pending,
        'NOT_STARTED' => VerificationStatus::Pending,
        'In Progress' => VerificationStatus::Processing,
        'IN_PROGRESS' => VerificationStatus::Processing,
        'In Review' => VerificationStatus::RequiresReview,
        'IN_REVIEW' => VerificationStatus::RequiresReview,
        'Approved' => VerificationStatus::Verified,
        'APPROVED' => VerificationStatus::Verified,
        'Declined' => VerificationStatus::Rejected,
        'Expired' => VerificationStatus::Expired,
        'KYC Expired' => VerificationStatus::Expired,
        'Abandoned' => VerificationStatus::Rejected,
        'Resubmitted' => VerificationStatus::ReverificationRequired,
    ];

    foreach ($cases as $raw => $expected) {
        expect(DiditStatusMap::status($raw))->toBe($expected);
    }
});

it('routes an unrecognised status to review rather than approving it', function (): void {
    // A provider vocabulary change must never silently verify a rider.
    expect(DiditStatusMap::status('Something Entirely New'))->toBe(VerificationStatus::RequiresReview)
        ->and(DiditStatusMap::isKnownStatus('Something Entirely New'))->toBeFalse();
});

it('derives the most actionable rejection reason from the per-check blocks', function (): void {
    expect(DiditStatusMap::reason('Declined', ['aml' => ['status' => 'Declined']]))
        ->toBe(RejectionReason::SanctionsHit)
        ->and(DiditStatusMap::reason('Declined', ['liveness' => ['status' => 'Failed']]))
        ->toBe(RejectionReason::LivenessFailed)
        ->and(DiditStatusMap::reason('Declined', ['face_match' => ['status' => 'Failed']]))
        ->toBe(RejectionReason::FaceMismatch)
        ->and(DiditStatusMap::reason('Declined', ['kyc' => ['status' => 'Declined', 'warning' => 'Document expired']]))
        ->toBe(RejectionReason::DocumentExpired)
        ->and(DiditStatusMap::reason('Abandoned', []))
        ->toBe(RejectionReason::AbandonedBySubject)
        ->and(DiditStatusMap::reason('Approved', []))->toBeNull();
});

// -------------------------------------------------------------- signatures --

it('accepts a correctly signed V2 webhook', function (): void {
    $payload = [
        'session_id' => 'sess_abc123',
        'status' => 'Approved',
        'vendor_data' => 'case-1',
        'created_at' => time(),
        'timestamp' => time(),
        'webhook_type' => 'status.updated',
    ];
    $raw = json_encode($payload, JSON_UNESCAPED_SLASHES);

    $notification = diditProvider(new HttpFactory())->parseWebhook(
        $raw,
        WebhookHeaders::fromArray(['X-Signature-V2' => diditV2Signature($payload)]),
    );

    expect($notification->providerReference)->toBe('sess_abc123')
        ->and($notification->signatureScheme)->toBe('v2')
        ->and($notification->decision->status)->toBe(VerificationStatus::Verified);
});

it('accepts a correctly signed Simple webhook', function (): void {
    $payload = [
        'session_id' => 'sess_simple',
        'status' => 'Declined',
        'created_at' => time(),
        'timestamp' => time(),
        'webhook_type' => 'status.updated',
    ];
    $raw = json_encode($payload);

    $notification = diditProvider(new HttpFactory())->parseWebhook(
        $raw,
        WebhookHeaders::fromArray(['X-Signature-Simple' => diditSimpleSignature($payload)]),
    );

    expect($notification->signatureScheme)->toBe('simple')
        ->and($notification->decision->status)->toBe(VerificationStatus::Rejected);
});

it('accepts a correctly signed raw-body webhook', function (): void {
    $payload = ['session_id' => 'sess_raw', 'status' => 'Approved', 'created_at' => time(), 'timestamp' => time(), 'webhook_type' => 'x'];
    $raw = json_encode($payload);

    $notification = diditProvider(new HttpFactory())->parseWebhook(
        $raw,
        WebhookHeaders::fromArray(['X-Signature' => hash_hmac('sha256', $raw, DIDIT_SECRET)]),
    );

    expect($notification->signatureScheme)->toBe('original');
});

it('rejects a forged signature', function (): void {
    $payload = ['session_id' => 's', 'status' => 'Approved', 'created_at' => time(), 'timestamp' => time(), 'webhook_type' => 'x'];
    $raw = json_encode($payload);

    expect(fn () => diditProvider(new HttpFactory())->parseWebhook(
        $raw,
        WebhookHeaders::fromArray(['X-Signature' => str_repeat('a', 64)]),
    ))->toThrow(WebhookRejected::class);
});

it('rejects a webhook with no signature at all', function (): void {
    $payload = ['session_id' => 's', 'status' => 'Approved', 'created_at' => time(), 'timestamp' => time()];

    expect(fn () => diditProvider(new HttpFactory())->parseWebhook(
        json_encode($payload),
        WebhookHeaders::fromArray([]),
    ))->toThrow(WebhookRejected::class);
});

it('rejects a replayed webhook outside the tolerance window', function (): void {
    $stale = time() - 3600;
    $payload = ['session_id' => 's', 'status' => 'Approved', 'created_at' => $stale, 'timestamp' => $stale, 'webhook_type' => 'x'];
    $raw = json_encode($payload);

    // Correctly signed, but far too old — a captured-and-replayed delivery.
    expect(fn () => diditProvider(new HttpFactory())->parseWebhook(
        $raw,
        WebhookHeaders::fromArray(['X-Signature' => hash_hmac('sha256', $raw, DIDIT_SECRET)]),
    ))->toThrow(WebhookRejected::class);
});

it('refuses to verify anything when no webhook secret is configured', function (): void {
    // A blank secret must never degrade to "skip the check".
    $payload = ['session_id' => 's', 'status' => 'Approved', 'created_at' => time(), 'timestamp' => time()];
    $raw = json_encode($payload);
    $provider = diditProvider(new HttpFactory(), ['webhook_secret' => '']);

    expect(fn () => $provider->parseWebhook(
        $raw,
        WebhookHeaders::fromArray(['X-Signature' => hash_hmac('sha256', $raw, '')]),
    ))->toThrow(WebhookRejected::class);
});

it('rejects a malformed body before attempting anything with it', function (): void {
    expect(fn () => diditProvider(new HttpFactory())->parseWebhook(
        'not json at all',
        WebhookHeaders::fromArray(['X-Signature' => 'x']),
    ))->toThrow(WebhookRejected::class);
});

it('derives a stable event id so a redelivery dedupes but a real change does not', function (): void {
    // Didit sends no explicit event id, so the exactly-once key is derived from
    // what identifies the delivery. It has to be stable across a redelivery of
    // the same change and different across a genuine status change — otherwise
    // either every retry re-applies, or a real transition is swallowed.
    $now = time();
    $base = ['session_id' => 'sess_1', 'created_at' => $now, 'timestamp' => $now, 'webhook_type' => 'status.updated'];
    $provider = diditProvider(new HttpFactory());

    $sign = static fn (array $payload): WebhookHeaders => WebhookHeaders::fromArray([
        'X-Signature' => hash_hmac('sha256', json_encode($payload), DIDIT_SECRET),
    ]);

    $approved = $base + ['status' => 'Approved'];
    $first = $provider->parseWebhook(json_encode($approved), $sign($approved));
    $repeat = $provider->parseWebhook(json_encode($approved), $sign($approved));

    $declined = $base + ['status' => 'Declined'];
    $different = $provider->parseWebhook(json_encode($declined), $sign($declined));

    expect($first->providerEventId)->toBe($repeat->providerEventId)
        ->and($first->providerEventId)->not->toBe($different->providerEventId);
});
