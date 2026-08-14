<?php

declare(strict_types=1);

namespace EruoFood\Geo\Infrastructure\Provider;

use EruoFood\Geo\Application\Port\DistanceMatrixProvider;
use EruoFood\Geo\Application\Port\GeocodingProvider;
use EruoFood\Geo\Application\Port\GeoProviderRegistry;
use EruoFood\Geo\Application\Port\PlacesProvider;
use EruoFood\Geo\Application\Port\RoutingProvider;
use EruoFood\Geo\Domain\Exception\GeoProviderUnavailable;

/**
 * Resolves providers from configuration, by capability and country.
 *
 * Factories are closures, so naming a provider does not construct it — an
 * unconfigured adapter costs nothing until something actually asks for it, and
 * a deployment with no Google credentials can still boot and run everything
 * that does not need them.
 *
 * An unresolvable capability raises. The tempting alternative — return null and
 * let the caller cope — is how a delivery ends up priced against a distance
 * nobody measured.
 */
final readonly class ConfigGeoProviderRegistry implements GeoProviderRegistry
{
    /**
     * @param array<string, callable(): object> $factories provider name => factory
     * @param array<string, array{default?: string, by_country?: array<string, string>}> $routing
     */
    public function __construct(
        private array $factories,
        private array $routing,
    ) {
    }

    public function geocoding(?string $countryCode = null): GeocodingProvider
    {
        $provider = $this->resolve('geocoding', $countryCode);

        return $provider instanceof GeocodingProvider
            ? $provider
            : throw GeoProviderUnavailable::because('The configured provider cannot geocode.');
    }

    public function routing(?string $countryCode = null): RoutingProvider
    {
        $provider = $this->resolve('routing', $countryCode);

        return $provider instanceof RoutingProvider
            ? $provider
            : throw GeoProviderUnavailable::because('The configured provider cannot calculate routes.');
    }

    public function distanceMatrix(?string $countryCode = null): DistanceMatrixProvider
    {
        $provider = $this->resolve('routing', $countryCode);

        return $provider instanceof DistanceMatrixProvider
            ? $provider
            : throw GeoProviderUnavailable::because('The configured provider cannot calculate distance matrices.');
    }

    public function places(?string $countryCode = null): PlacesProvider
    {
        $provider = $this->resolve('places', $countryCode);

        return $provider instanceof PlacesProvider
            ? $provider
            : throw GeoProviderUnavailable::because('The configured provider cannot suggest places.');
    }

    private function resolve(string $capability, ?string $countryCode): object
    {
        $entry = $this->routing[$capability] ?? [];
        $country = $countryCode === null ? null : strtoupper($countryCode);

        $name = ($country !== null ? ($entry['by_country'][$country] ?? null) : null)
            ?? ($entry['default'] ?? '');

        if ($name === '' || ! isset($this->factories[$name])) {
            throw GeoProviderUnavailable::noProviderFor($capability, $country ?? 'default');
        }

        return ($this->factories[$name])();
    }
}
