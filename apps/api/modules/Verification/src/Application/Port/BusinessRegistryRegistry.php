<?php

declare(strict_types=1);

namespace EruoFood\Verification\Application\Port;

/**
 * Resolves the business registry for a country.
 *
 * Returns null rather than throwing when a country has no configured registry,
 * because "this market has no registry integration" is a normal state that
 * routes to manual review — not an error. What must never happen is a country
 * without a registry being treated as automatically verified.
 */
interface BusinessRegistryRegistry
{
    public function forCountry(string $countryCode): ?BusinessRegistryProvider;

    /** @return list<string> countries with a configured registry adapter */
    public function supportedCountries(): array;
}
