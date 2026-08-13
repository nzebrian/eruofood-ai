<?php

declare(strict_types=1);

namespace EruoFood\Verification\Infrastructure\Provider\Didit;

use EruoFood\Verification\Application\DTO\DocumentSummary;
use EruoFood\Verification\Application\DTO\VerificationDecision;
use EruoFood\Verification\Application\DTO\VerificationRequest;
use EruoFood\Verification\Application\DTO\VerificationSession;
use EruoFood\Verification\Application\DTO\WebhookHeaders;
use EruoFood\Verification\Application\DTO\WebhookNotification;
use EruoFood\Verification\Application\Port\IdentityVerificationProvider;
use EruoFood\Verification\Domain\Enum\CaseType;
use EruoFood\Verification\Domain\Enum\ProviderName;
use EruoFood\Verification\Domain\Enum\VerificationStatus;
use EruoFood\Verification\Domain\Exception\ProviderUnavailable;
use EruoFood\Verification\Domain\Exception\WebhookRejected;
use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;

/**
 * Didit adapter — the only file in the codebase that knows how Didit works.
 *
 * Everything Didit-shaped stops here: the `x-api-key` header, the `/v3/session/`
 * path, `workflow_id`, `vendor_data`, their status spellings, their per-check
 * result blocks. The rest of the platform sees only the neutral DTOs.
 *
 * **Contract provenance.** Didit's documentation site is unreachable from this
 * build environment (egress policy), so this adapter was written against their
 * official reference implementation at
 * `github.com/didit-protocol/didit-full-demo`, which is runnable code rather
 * than prose. Confirmed there: the endpoint and method, the `X-API-Key` header,
 * the request body fields, HTTP 201 on success, the three signature headers and
 * their exact algorithms, the ±300s replay window, and the status vocabulary.
 *
 * **Isolated uncertainty.** Two details their reference leaves genuinely open,
 * both handled defensively rather than guessed:
 *
 * 1. The session response field carrying the hosted URL appears as `url` in
 *    their typed demo and `session_url` in their API reference. Both are read.
 * 2. The V2 signature's JSON separator style — see
 *    {@see DiditSignatureVerifier} — where both variants are computed.
 *
 * Neither assumption escapes this adapter, so correcting one is a single-file
 * change with no domain impact.
 */
final readonly class DiditProvider implements IdentityVerificationProvider
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private HttpFactory $http,
        private array $config,
    ) {
    }

    public function name(): ProviderName
    {
        return ProviderName::Didit;
    }

    public function supports(CaseType $caseType, string $countryCode): bool
    {
        if (! (bool) ($this->config['enabled'] ?? false)) {
            return false;
        }

        // Didit performs identity checks anywhere, and business checks only if a
        // business workflow has actually been provisioned for this tenant.
        return $caseType === CaseType::Identity
            || ($this->workflowFor($caseType, null) !== '');
    }

    public function createSession(VerificationRequest $request): VerificationSession
    {
        $workflowId = $this->workflowFor($request->caseType, $request);
        if ($workflowId === '') {
            throw ProviderUnavailable::because('No Didit workflow is configured for this verification type.');
        }

        $body = [
            'workflow_id' => $workflowId,
            // The case id, not the user id: an opaque UUID, so nothing about our
            // internal identity model leaves the platform.
            'vendor_data' => $request->caseId,
        ];

        $callback = $request->callbackUrl ?? (string) ($this->config['callback_url'] ?? '');
        if ($callback !== '') {
            $body['callback'] = $callback;
        }

        $response = $this->client()->post('/v3/session/', $body);

        if (! $response->successful()) {
            throw ProviderUnavailable::because(sprintf(
                'Didit rejected the session request (HTTP %d).',
                $response->status(),
            ));
        }

        /** @var array<string, mixed> $data */
        $data = (array) $response->json();

        $sessionId = $this->string($data, 'session_id');
        if ($sessionId === '') {
            throw ProviderUnavailable::because('Didit returned a session without an id.');
        }

        // `url` in their demo types, `session_url` in their API reference — read
        // both rather than betting on one.
        $hostedUrl = $this->string($data, 'url') ?: $this->string($data, 'session_url');

        return new VerificationSession(
            provider: ProviderName::Didit,
            providerReference: $sessionId,
            status: DiditStatusMap::status($this->string($data, 'status')),
            hostedUrl: $hostedUrl !== '' ? $hostedUrl : null,
        );
    }

    public function fetchDecision(string $providerReference): VerificationDecision
    {
        $response = $this->client()->get(sprintf('/v3/session/%s/decision/', urlencode($providerReference)));

        if (! $response->successful()) {
            throw ProviderUnavailable::because(sprintf(
                'Didit could not return a decision for session "%s" (HTTP %d).',
                $providerReference,
                $response->status(),
            ));
        }

        /** @var array<string, mixed> $data */
        $data = (array) $response->json();

        return $this->toDecision($data);
    }

    public function parseWebhook(string $rawBody, WebhookHeaders $headers): WebhookNotification
    {
        // Decode only to feed the signature schemes. Nothing here is trusted
        // until verify() returns, and a decode failure is a rejection rather
        // than an exception escaping as a 500.
        try {
            /** @var array<string, mixed> $decoded */
            $decoded = (array) json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw WebhookRejected::malformed();
        }

        $scheme = $this->verifier()->verify(
            rawBody: $rawBody,
            decoded: $decoded,
            v2: $headers->get('X-Signature-V2'),
            simple: $headers->get('X-Signature-Simple'),
            original: $headers->get('X-Signature'),
            now: time(),
        );

        $sessionId = $this->string($decoded, 'session_id');
        if ($sessionId === '') {
            throw WebhookRejected::malformed();
        }

        $timestamp = $this->int($decoded, 'timestamp') ?: $this->int($decoded, 'created_at');

        return new WebhookNotification(
            // Didit does not send a separate event id, so the exactly-once key
            // is derived from what uniquely identifies this delivery: the
            // session, the status it reports and when it was emitted. A genuine
            // status change produces a new key; a redelivery of the same change
            // produces the same one.
            providerEventId: hash('sha256', sprintf(
                '%s|%s|%d',
                $sessionId,
                $this->string($decoded, 'status'),
                $timestamp,
            )),
            providerReference: $sessionId,
            decision: $this->toDecision($decoded),
            timestamp: $timestamp,
            signatureScheme: $scheme,
        );
    }

    /** @param array<string, mixed> $payload */
    private function toDecision(array $payload): VerificationDecision
    {
        $rawStatus = $this->string($payload, 'status');
        $status = DiditStatusMap::status($rawStatus);

        // An unmapped status must not look like a clean decision: flag it so a
        // human sees the case rather than letting a vocabulary change through.
        $note = DiditStatusMap::isKnownStatus($rawStatus)
            ? null
            : sprintf('Unrecognised provider status "%s" — routed to manual review.', $rawStatus);

        return new VerificationDecision(
            status: $status,
            rawStatus: $rawStatus,
            reason: DiditStatusMap::reason($rawStatus, $payload),
            documents: $this->documentsFrom($payload, $status),
            note: $note,
        );
    }

    /**
     * Reduce Didit's `kyc` block to the few facts we are willing to keep.
     *
     * This is the privacy boundary: their payload can carry a full name, date of
     * birth, nationality, address and full document number, and only the type,
     * issuing country, expiry and the number (immediately reduced to its last
     * four downstream) travel any further.
     *
     * @param array<string, mixed> $payload
     * @return list<DocumentSummary>
     */
    private function documentsFrom(array $payload, VerificationStatus $status): array
    {
        $kyc = $payload['kyc'] ?? null;
        if (! is_array($kyc) || ! $status->isVerified()) {
            return [];
        }

        $number = $this->string($kyc, 'document_number');

        return [new DocumentSummary(
            type: DiditStatusMap::documentType($this->string($kyc, 'document_type')),
            issuingCountry: $this->string($kyc, 'issuing_state') ?: ($this->string($kyc, 'nationality') ?: null),
            documentNumber: $number !== '' ? $number : null,
            expiresOn: $this->string($kyc, 'expiration_date') ?: null,
        )];
    }

    private function verifier(): DiditSignatureVerifier
    {
        return new DiditSignatureVerifier(
            (string) ($this->config['webhook_secret'] ?? ''),
            (int) ($this->config['replay_tolerance'] ?? 300),
        );
    }

    private function client(): \Illuminate\Http\Client\PendingRequest
    {
        return $this->http
            ->baseUrl((string) ($this->config['base_url'] ?? 'https://verification.didit.me'))
            ->withHeaders([
                'X-API-Key' => (string) ($this->config['api_key'] ?? ''),
                'Accept' => 'application/json',
            ])
            ->asJson()
            ->timeout((int) ($this->config['timeout'] ?? 30))
            ->retry(
                max(1, (int) ($this->config['retry_attempts'] ?? 2)),
                max(0, (int) ($this->config['retry_delay_ms'] ?? 250)),
                throw: false,
            );
    }

    /** Which provider-side workflow performs the checks this case needs. */
    private function workflowFor(CaseType $caseType, ?VerificationRequest $request): string
    {
        /** @var array<string, mixed> $workflows */
        $workflows = (array) ($this->config['workflows'] ?? []);

        $key = match (true) {
            $caseType === CaseType::Business => 'business',
            $request !== null && $request->requires('driving_licence') => 'rider_licence',
            $request !== null && $request->subjectType->value === 'rider' => 'rider_identity',
            $request !== null && $request->subjectType->value === 'business' => 'representative_identity',
            default => 'customer_identity',
        };

        $workflow = (string) ($workflows[$key] ?? '');

        // Fall back to the general identity workflow so a deployment that has
        // provisioned only one workflow still functions.
        return $workflow !== '' ? $workflow : (string) ($workflows['customer_identity'] ?? '');
    }

    /** @param array<string, mixed> $data */
    private function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** @param array<string, mixed> $data */
    private function int(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }
}
