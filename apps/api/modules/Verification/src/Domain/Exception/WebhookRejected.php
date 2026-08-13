<?php

declare(strict_types=1);

namespace EruoFood\Verification\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/**
 * An inbound provider webhook was refused before it could change anything.
 *
 * Signature mismatch, a timestamp outside the replay window, or a provider
 * reference that maps to no attempt. The caller gets a bare 401 with no detail:
 * a webhook endpoint that explains *why* it rejected a forgery is a tool for
 * refining the forgery.
 */
final class WebhookRejected extends DomainException
{
    public static function badSignature(): self
    {
        return new self('Webhook signature verification failed.');
    }

    public static function replayed(): self
    {
        return new self('Webhook timestamp is outside the accepted replay window.');
    }

    public static function unknownReference(): self
    {
        return new self('Webhook provider reference does not match any verification attempt.');
    }

    public static function malformed(): self
    {
        return new self('Webhook payload is malformed.');
    }

    public function errorCode(): string
    {
        return 'VERIFICATION_WEBHOOK_REJECTED';
    }
}
