<?php

declare(strict_types=1);

namespace EruoFood\Verification\Application\DTO;

/**
 * A signature-verified provider callback, normalised.
 *
 * An adapter only returns this once it has proved the payload came from the
 * provider. Anything that fails verification raises
 * {@see \EruoFood\Verification\Domain\Exception\WebhookRejected} instead, so
 * there is no route by which an unverified payload reaches the service layer
 * carrying a "verified" decision.
 */
final readonly class WebhookNotification
{
    public function __construct(
        /** Stable per-event id, used as the exactly-once key. */
        public string $providerEventId,
        public string $providerReference,
        public VerificationDecision $decision,
        /** Unix seconds, already checked against the replay window. */
        public int $timestamp,
        /** Which signature scheme actually verified — recorded for audit. */
        public string $signatureScheme,
    ) {
    }
}
