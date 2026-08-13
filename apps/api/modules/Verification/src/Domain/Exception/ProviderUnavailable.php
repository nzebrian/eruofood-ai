<?php

declare(strict_types=1);

namespace EruoFood\Verification\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/**
 * No provider could handle this case, or the chosen one failed.
 *
 * Never silently downgraded to an approval: if the platform cannot verify, the
 * subject stays unverified.
 */
final class ProviderUnavailable extends DomainException
{
    public static function forCase(string $caseType, string $countryCode): self
    {
        return new self(sprintf(
            'No verification provider is configured for %s cases in country "%s".',
            $caseType,
            $countryCode,
        ));
    }

    public static function because(string $reason): self
    {
        return new self($reason);
    }

    public function errorCode(): string
    {
        return 'VERIFICATION_PROVIDER_UNAVAILABLE';
    }
}
