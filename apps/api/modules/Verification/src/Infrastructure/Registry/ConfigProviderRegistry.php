<?php

declare(strict_types=1);

namespace EruoFood\Verification\Infrastructure\Registry;

use EruoFood\Verification\Application\Port\IdentityVerificationProvider;
use EruoFood\Verification\Application\Port\VerificationProviderRegistry;
use EruoFood\Verification\Domain\Enum\CaseType;
use EruoFood\Verification\Domain\Enum\ProviderName;
use EruoFood\Verification\Domain\Exception\ProviderUnavailable;

/**
 * Routes a case to its provider from configuration.
 *
 * Two lookups, both data-driven: an explicit per-country choice if one exists,
 * otherwise the default for that case type. A country with no entry does *not*
 * silently succeed — if the resolved provider cannot support the case, this
 * raises rather than falling back to something that would approve it.
 *
 * Providers are resolved lazily through a closure map so that configuring a
 * provider does not construct it, and an unconfigured provider's absence is
 * only felt if something actually asks for it.
 */
final class ConfigProviderRegistry implements VerificationProviderRegistry
{
    /** @var array<string, IdentityVerificationProvider> */
    private array $resolved = [];

    /**
     * @param array<string, callable():IdentityVerificationProvider> $factories
     * @param array<string, mixed> $routing
     */
    public function __construct(
        private readonly array $factories,
        private readonly array $routing,
    ) {
    }

    public function for(ProviderName $name): IdentityVerificationProvider
    {
        if (isset($this->resolved[$name->value])) {
            return $this->resolved[$name->value];
        }

        $factory = $this->factories[$name->value] ?? null;
        if ($factory === null) {
            throw ProviderUnavailable::because(sprintf('Verification provider "%s" is not registered.', $name->value));
        }

        return $this->resolved[$name->value] = $factory();
    }

    public function resolve(CaseType $caseType, string $countryCode): IdentityVerificationProvider
    {
        $country = strtoupper($countryCode);

        /** @var array<string, mixed> $section */
        $section = (array) ($this->routing[$caseType->value] ?? []);
        /** @var array<string, mixed> $byCountry */
        $byCountry = (array) ($section['by_country'] ?? []);

        $name = (string) ($byCountry[$country] ?? $section['default'] ?? '');
        if ($name === '') {
            throw ProviderUnavailable::forCase($caseType->value, $country);
        }

        $provider = $this->for(ProviderName::tryFrom($name) ?? throw ProviderUnavailable::because(
            sprintf('Configured verification provider "%s" is not a known provider.', $name),
        ));

        if (! $provider->supports($caseType, $country)) {
            throw ProviderUnavailable::forCase($caseType->value, $country);
        }

        return $provider;
    }
}
