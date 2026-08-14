<?php

declare(strict_types=1);

namespace EruoFood\Geo\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/**
 * The mapping provider could not be reached, or refused.
 *
 * Deliberately carries no provider detail in its message. A Google error string
 * can name the API key, the quota project, or the exact query — none of which
 * belongs in an API response. The specifics go to the log; the caller gets a
 * neutral 503.
 */
final class GeoProviderUnavailable extends DomainException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }

    public static function noProviderFor(string $capability, string $countryCode): self
    {
        return new self(sprintf(
            'No %s provider is configured for country "%s".',
            $capability,
            $countryCode,
        ));
    }

    public static function circuitOpen(string $capability): self
    {
        return new self(sprintf(
            'The %s service is temporarily unavailable after repeated failures.',
            $capability,
        ));
    }

    public function errorCode(): string
    {
        return 'GEO_PROVIDER_UNAVAILABLE';
    }
}
