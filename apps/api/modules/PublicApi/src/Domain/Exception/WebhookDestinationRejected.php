<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/**
 * Raised when a webhook destination URL is refused by the SSRF/egress policy —
 * an unsupported scheme, a disallowed port, a URL carrying credentials, or a
 * host that resolves to a private, loopback, link-local or otherwise reserved
 * address. Blocking such destinations stops the webhook system from being used
 * to reach internal services (server-side request forgery).
 */
final class WebhookDestinationRejected extends DomainException
{
    public static function scheme(string $scheme): self
    {
        return new self(sprintf('Webhook URL scheme "%s" is not allowed.', $scheme));
    }

    public static function insecure(): self
    {
        return new self('Webhook URLs must use HTTPS.');
    }

    public static function malformed(): self
    {
        return new self('The webhook URL is malformed.');
    }

    public static function credentials(): self
    {
        return new self('Webhook URLs must not contain credentials.');
    }

    public static function port(int $port): self
    {
        return new self(sprintf('Webhook URL port %d is not allowed.', $port));
    }

    public static function unresolvable(string $host): self
    {
        return new self(sprintf('The webhook host "%s" could not be resolved.', $host));
    }

    public static function privateAddress(string $host): self
    {
        return new self(sprintf('The webhook host "%s" resolves to a private or reserved address.', $host));
    }

    public function errorCode(): string
    {
        return 'PUBLICAPI_WEBHOOK_DESTINATION_REJECTED';
    }
}
