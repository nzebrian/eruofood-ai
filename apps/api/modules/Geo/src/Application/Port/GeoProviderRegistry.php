<?php

declare(strict_types=1);

namespace EruoFood\Geo\Application\Port;

/**
 * Resolves the provider for a capability in a country.
 *
 * Capability-and-country rather than a single global provider, so a market can
 * use a regional service without the domain learning anything about it. An
 * unconfigured capability raises rather than falling back to "no provider,
 * therefore fine" — silently answering with nothing is how a delivery gets
 * priced against a distance nobody measured.
 */
interface GeoProviderRegistry
{
    public function geocoding(?string $countryCode = null): GeocodingProvider;

    public function routing(?string $countryCode = null): RoutingProvider;

    public function distanceMatrix(?string $countryCode = null): DistanceMatrixProvider;

    public function places(?string $countryCode = null): PlacesProvider;
}
