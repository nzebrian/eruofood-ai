<?php

declare(strict_types=1);

namespace EruoFood\Verification\Infrastructure\Registry;

use EruoFood\Verification\Application\Port\BusinessRegistryProvider;
use EruoFood\Verification\Application\Port\BusinessRegistryRegistry;

/**
 * Resolves a country's business registry, or reports that it has none.
 *
 * Returning null for an unconfigured country is the whole point: it forces the
 * caller to decide what "no registry here" means (manual review) instead of
 * letting CAC — or any single country's registry — become an implicit global
 * assumption.
 *
 * @phpstan-type RegistryFactory callable():BusinessRegistryProvider
 */
final class ConfigBusinessRegistryRegistry implements BusinessRegistryRegistry
{
    /** @var array<string, BusinessRegistryProvider> */
    private array $resolved = [];

    /** @param array<string, callable():BusinessRegistryProvider> $factories keyed by ISO country code */
    public function __construct(private readonly array $factories)
    {
    }

    public function forCountry(string $countryCode): ?BusinessRegistryProvider
    {
        $country = strtoupper($countryCode);

        if (isset($this->resolved[$country])) {
            return $this->resolved[$country];
        }

        $factory = $this->factories[$country] ?? null;

        return $factory === null ? null : ($this->resolved[$country] = $factory());
    }

    public function supportedCountries(): array
    {
        return array_keys($this->factories);
    }
}
