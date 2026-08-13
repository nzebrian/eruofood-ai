<?php

declare(strict_types=1);

namespace EruoFood\Verification\Infrastructure\Provider\Mock;

use EruoFood\Verification\Application\DTO\DocumentSummary;
use EruoFood\Verification\Application\DTO\VerificationDecision;
use EruoFood\Verification\Application\DTO\VerificationRequest;
use EruoFood\Verification\Application\DTO\VerificationSession;
use EruoFood\Verification\Application\DTO\WebhookHeaders;
use EruoFood\Verification\Application\DTO\WebhookNotification;
use EruoFood\Verification\Application\Port\IdentityVerificationProvider;
use EruoFood\Verification\Domain\Enum\CaseType;
use EruoFood\Verification\Domain\Enum\DocumentType;
use EruoFood\Verification\Domain\Enum\ProviderName;
use EruoFood\Verification\Domain\Enum\RejectionReason;
use EruoFood\Verification\Domain\Enum\VerificationStatus;
use EruoFood\Verification\Domain\Exception\WebhookRejected;
use Throwable;

/**
 * A deterministic, offline provider.
 *
 * Forced in testing so the suite never reaches the network — the same choice
 * `config/payments.php` makes with its mock gateway. It is not a stub: it signs
 * and verifies webhooks with real HMAC-SHA256 and enforces the same replay
 * window, so the security tests exercise genuine cryptography rather than a
 * bypass that would pass regardless.
 *
 * Behaviour is steered by the case id so a test can ask for a specific outcome
 * without wiring a fake: a case id containing "decline" is rejected, "review"
 * goes to manual review, anything else is approved.
 */
final readonly class MockProvider implements IdentityVerificationProvider
{
    /** @param array<string, mixed> $config */
    public function __construct(private array $config)
    {
    }

    public function name(): ProviderName
    {
        return ProviderName::Mock;
    }

    public function supports(CaseType $caseType, string $countryCode): bool
    {
        return true;
    }

    public function createSession(VerificationRequest $request): VerificationSession
    {
        return new VerificationSession(
            provider: ProviderName::Mock,
            providerReference: 'mock_'.substr(hash('sha256', $request->caseId), 0, 24),
            status: VerificationStatus::Pending,
            hostedUrl: 'https://mock.verification.local/session/'.$request->caseId,
        );
    }

    public function fetchDecision(string $providerReference): VerificationDecision
    {
        return $this->decisionFor($providerReference);
    }

    public function parseWebhook(string $rawBody, WebhookHeaders $headers): WebhookNotification
    {
        try {
            /** @var array<string, mixed> $decoded */
            $decoded = (array) json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw WebhookRejected::malformed();
        }

        $secret = (string) ($this->config['webhook_secret'] ?? '');
        $signature = $headers->get('X-Signature');

        if ($secret === '' || $signature === null || ! hash_equals(hash_hmac('sha256', $rawBody, $secret), strtolower($signature))) {
            throw WebhookRejected::badSignature();
        }

        $timestamp = isset($decoded['timestamp']) && is_numeric($decoded['timestamp'])
            ? (int) $decoded['timestamp']
            : 0;

        $tolerance = (int) ($this->config['replay_tolerance'] ?? 300);
        if ($timestamp === 0 || abs(time() - $timestamp) > $tolerance) {
            throw WebhookRejected::replayed();
        }

        $reference = isset($decoded['reference']) && is_string($decoded['reference']) ? $decoded['reference'] : '';
        if ($reference === '') {
            throw WebhookRejected::malformed();
        }

        $status = isset($decoded['status']) && is_string($decoded['status']) ? $decoded['status'] : '';

        return new WebhookNotification(
            providerEventId: isset($decoded['event_id']) && is_string($decoded['event_id'])
                ? $decoded['event_id']
                : hash('sha256', $reference.'|'.$status.'|'.$timestamp),
            providerReference: $reference,
            decision: $status !== '' ? $this->decisionFromStatus($status, $reference) : $this->decisionFor($reference),
            timestamp: $timestamp,
            signatureScheme: 'mock',
        );
    }

    /** Sign a payload the way this provider expects. Test helper, not production code. */
    public static function sign(string $rawBody, string $secret): string
    {
        return hash_hmac('sha256', $rawBody, $secret);
    }

    private function decisionFor(string $providerReference): VerificationDecision
    {
        return $this->decisionFromStatus($this->impliedStatus($providerReference), $providerReference);
    }

    private function impliedStatus(string $providerReference): string
    {
        return match (true) {
            str_contains($providerReference, 'decline') => 'declined',
            str_contains($providerReference, 'review') => 'in_review',
            default => 'approved',
        };
    }

    private function decisionFromStatus(string $status, string $providerReference): VerificationDecision
    {
        $normalised = strtolower(str_replace([' ', '-'], '_', trim($status)));

        return match ($normalised) {
            'declined' => new VerificationDecision(
                status: VerificationStatus::Rejected,
                rawStatus: $status,
                reason: RejectionReason::DocumentUnreadable,
            ),
            'in_review' => new VerificationDecision(
                status: VerificationStatus::RequiresReview,
                rawStatus: $status,
            ),
            'expired' => new VerificationDecision(
                status: VerificationStatus::Expired,
                rawStatus: $status,
                reason: RejectionReason::DocumentExpired,
            ),
            'in_progress' => new VerificationDecision(
                status: VerificationStatus::Processing,
                rawStatus: $status,
            ),
            default => new VerificationDecision(
                status: VerificationStatus::Verified,
                rawStatus: $status,
                documents: [new DocumentSummary(
                    type: DocumentType::NationalId,
                    issuingCountry: 'NG',
                    documentNumber: 'MOCK'.substr(hash('sha256', $providerReference), 0, 8),
                    expiresOn: '2030-01-01',
                )],
            ),
        };
    }
}
