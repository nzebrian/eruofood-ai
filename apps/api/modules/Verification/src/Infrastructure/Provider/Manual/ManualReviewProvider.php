<?php

declare(strict_types=1);

namespace EruoFood\Verification\Infrastructure\Provider\Manual;

use EruoFood\Verification\Application\DTO\VerificationDecision;
use EruoFood\Verification\Application\DTO\VerificationRequest;
use EruoFood\Verification\Application\DTO\VerificationSession;
use EruoFood\Verification\Application\DTO\WebhookHeaders;
use EruoFood\Verification\Application\DTO\WebhookNotification;
use EruoFood\Verification\Application\Port\IdentityVerificationProvider;
use EruoFood\Verification\Domain\Enum\CaseType;
use EruoFood\Verification\Domain\Enum\ProviderName;
use EruoFood\Verification\Domain\Enum\VerificationStatus;
use EruoFood\Verification\Domain\Exception\WebhookRejected;
use Illuminate\Support\Str;

/**
 * The fallback for anything no automated provider can settle.
 *
 * It is a genuine provider in the sense that it opens a case and produces a
 * reference, but it makes no decision: every case it handles lands in
 * RequiresReview for a human. That is the honest representation of "we have no
 * integration for this" — as opposed to approving by default, which is how
 * unverified merchants end up trading.
 *
 * This is a *fallback*, not the model for CAC. Nigerian business registration is
 * a {@see \EruoFood\Verification\Application\Port\BusinessRegistryProvider},
 * which validates and looks up the registration properly; it delegates here only
 * for the portion a registry genuinely cannot answer.
 */
final readonly class ManualReviewProvider implements IdentityVerificationProvider
{
    public function name(): ProviderName
    {
        return ProviderName::Manual;
    }

    public function supports(CaseType $caseType, string $countryCode): bool
    {
        // The universal backstop: always available, precisely so a case can never
        // fall through to "no provider, therefore fine".
        return true;
    }

    public function createSession(VerificationRequest $request): VerificationSession
    {
        return new VerificationSession(
            provider: ProviderName::Manual,
            providerReference: 'manual_'.Str::orderedUuid()->toString(),
            // Straight to the queue: there is nothing for the subject to do.
            status: VerificationStatus::RequiresReview,
            hostedUrl: null,
        );
    }

    public function fetchDecision(string $providerReference): VerificationDecision
    {
        return new VerificationDecision(
            status: VerificationStatus::RequiresReview,
            rawStatus: 'manual_review',
            note: 'Awaiting a decision from a compliance reviewer.',
        );
    }

    public function parseWebhook(string $rawBody, WebhookHeaders $headers): WebhookNotification
    {
        // Nothing external ever calls back for a manual case; accepting one
        // would be an unauthenticated path to changing verification state.
        throw WebhookRejected::badSignature();
    }
}
