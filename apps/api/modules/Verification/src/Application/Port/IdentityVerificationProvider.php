<?php

declare(strict_types=1);

namespace EruoFood\Verification\Application\Port;

use EruoFood\Verification\Application\DTO\VerificationDecision;
use EruoFood\Verification\Application\DTO\VerificationRequest;
use EruoFood\Verification\Application\DTO\VerificationSession;
use EruoFood\Verification\Application\DTO\WebhookHeaders;
use EruoFood\Verification\Application\DTO\WebhookNotification;
use EruoFood\Verification\Domain\Enum\CaseType;
use EruoFood\Verification\Domain\Enum\ProviderName;

/**
 * The contract every verification provider satisfies.
 *
 * Didit is the first implementation, not the shape of the interface. Nothing
 * here mentions workflows, session URLs in a provider's own spelling, or any
 * vendor field name — an adapter absorbs all of that so replacing or adding a
 * provider is an infrastructure change with no domain consequences.
 *
 * The parse step is a *verification* step, not a decoding step: an
 * implementation must prove the payload's authenticity before returning
 * anything, and raise {@see \EruoFood\Verification\Domain\Exception\WebhookRejected}
 * otherwise. That is why there is no separate "verifySignature" method a caller
 * could forget to invoke.
 */
interface IdentityVerificationProvider
{
    public function name(): ProviderName;

    /** Whether this provider can handle this kind of case in this country. */
    public function supports(CaseType $caseType, string $countryCode): bool;

    /** Open a provider session for the subject to complete. */
    public function createSession(VerificationRequest $request): VerificationSession;

    /**
     * Ask the provider what it decided.
     *
     * Used by reconciliation when a callback never arrived, so a lost webhook
     * cannot strand a subject indefinitely.
     */
    public function fetchDecision(string $providerReference): VerificationDecision;

    /**
     * Authenticate and normalise an inbound callback.
     *
     * @throws \EruoFood\Verification\Domain\Exception\WebhookRejected when the
     *                                                                 signature does not verify, the timestamp is outside the replay
     *                                                                 window, or the payload is malformed
     */
    public function parseWebhook(string $rawBody, WebhookHeaders $headers): WebhookNotification;
}
