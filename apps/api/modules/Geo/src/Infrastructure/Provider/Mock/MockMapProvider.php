<?php

declare(strict_types=1);

namespace EruoFood\Geo\Infrastructure\Provider\Mock;

use DateTimeImmutable;
use EruoFood\Geo\Application\DTO\GeocodeQuery;
use EruoFood\Geo\Application\DTO\GeocodeResult;
use EruoFood\Geo\Application\DTO\PlaceSuggestion;
use EruoFood\Geo\Application\DTO\RouteMatrixResult;
use EruoFood\Geo\Application\DTO\RouteQuery;
use EruoFood\Geo\Application\Port\DistanceMatrixProvider;
use EruoFood\Geo\Application\Port\GeocodingProvider;
use EruoFood\Geo\Application\Port\PlacesProvider;
use EruoFood\Geo\Application\Port\RoutingProvider;
use EruoFood\Geo\Domain\Enum\LocationPrecision;
use EruoFood\Geo\Domain\Enum\RouteSource;
use EruoFood\Geo\Domain\Enum\TravelMode;
use EruoFood\Geo\Domain\Exception\GeoAddressNotFound;
use EruoFood\Geo\Domain\Exception\GeoProviderUnavailable;
use EruoFood\Geo\Domain\Route\Route;
use EruoFood\Geo\Domain\ValueObject\Coordinates;
use EruoFood\Geo\Domain\ValueObject\Haversine;
use EruoFood\Geo\Domain\ValueObject\PostalAddress;

/**
 * A complete, offline, deterministic mapping provider.
 *
 * Not a stub. It geocodes, routes, fails, and returns results whose shape and
 * variability resemble a real provider's, so the suite exercises the genuine
 * decision paths — cache keys, fallback ordering, precision handling, error
 * translation — without a network call, an API key or a bill. This is the
 * arrangement M24's `MockProvider` established, and it is why M24's webhook
 * security could be tested properly.
 *
 * Behaviour is steered by the input rather than by randomness, so a test that
 * wants a failure asks for one by name:
 *
 * - an address containing "nowhere" is not found
 * - an address containing "outage" makes the provider unavailable
 * - an address containing "approximate" geocodes to a coarse match
 * - coordinates in the ocean square reverse-geocode to not-found
 *
 * Routed distances are derived from the straight line with a road factor of
 * 1.4, which is roughly the Lagos ratio. That number is deliberately *not* 1.0:
 * a mock that returned the haversine distance would let a bug that bypasses
 * routing pass every test.
 */
final readonly class MockMapProvider implements DistanceMatrixProvider, GeocodingProvider, PlacesProvider, RoutingProvider
{
    /** Roughly the ratio of road distance to straight line in a dense city. */
    private const ROAD_FACTOR = 1.4;

    /** Metres per second, about 24 km/h — plausible for a motorbike in traffic. */
    private const BASE_SPEED_MPS = 6.7;

    /** @param array<string, mixed> $config */
    public function __construct(private array $config = [])
    {
    }

    public function name(): string
    {
        return 'mock';
    }

    public function geocode(GeocodeQuery $query): GeocodeResult
    {
        $needle = mb_strtolower($query->address);

        if (str_contains($needle, 'outage')) {
            throw GeoProviderUnavailable::because('The mock provider was asked to fail.');
        }

        if (str_contains($needle, 'nowhere')) {
            throw GeoAddressNotFound::forQuery();
        }

        $precision = match (true) {
            str_contains($needle, 'approximate') => LocationPrecision::Approximate,
            str_contains($needle, 'centroid') => LocationPrecision::GeometricCentre,
            default => LocationPrecision::Rooftop,
        };

        $coordinates = $this->coordinatesFor($query->normalised());

        return new GeocodeResult(
            $coordinates,
            new PostalAddress(
                formatted: $this->formatted($query->address),
                line1: trim($query->address),
                district: 'Ikeja',
                locality: 'Lagos',
                adminArea: 'Lagos',
                subAdminArea: 'Ikeja',
                countryCode: strtoupper($query->countryCode ?? 'NG'),
                countryName: strtoupper($query->countryCode ?? 'NG') === 'NG' ? 'Nigeria' : null,
            ),
            $precision,
            $this->name(),
            'mock_place_'.substr(hash('sha256', $query->normalised()), 0, 16),
        );
    }

    public function reverseGeocode(Coordinates $coordinates, ?string $language = null): GeocodeResult
    {
        // A deliberate patch of "ocean" so the not-found path is reachable from
        // coordinates as well as from text.
        if ($coordinates->latitude < 1.0 && $coordinates->longitude < 1.0) {
            throw GeoAddressNotFound::forCoordinates();
        }

        return new GeocodeResult(
            $coordinates,
            new PostalAddress(
                formatted: sprintf('%s, Lagos, Nigeria', $this->streetFor($coordinates)),
                line1: $this->streetFor($coordinates),
                district: 'Ikeja',
                locality: 'Lagos',
                adminArea: 'Lagos',
                countryCode: 'NG',
                countryName: 'Nigeria',
            ),
            LocationPrecision::RangeInterpolated,
            $this->name(),
            'mock_place_'.substr(hash('sha256', $coordinates->toKey()), 0, 16),
        );
    }

    public function route(RouteQuery $query): Route
    {
        // Same escape hatch as geocoding: a route to the ocean square fails, so
        // the fallback chain can be driven end to end.
        if ($query->destination->latitude < 1.0 && $query->destination->longitude < 1.0) {
            throw GeoProviderUnavailable::because('The mock provider could not route to that destination.');
        }

        $straightLine = Haversine::metres($query->origin, $query->destination);
        $distance = (int) round($straightLine * self::ROAD_FACTOR);
        $duration = (int) round($distance / self::BASE_SPEED_MPS);

        return new Route(
            origin: $query->origin,
            destination: $query->destination,
            distanceMetres: $distance,
            durationSeconds: $duration,
            travelMode: $query->travelMode,
            source: RouteSource::Provider,
            provider: $this->name(),
            calculatedAt: new DateTimeImmutable(),
            // Traffic adds a deterministic 25%, so a test can tell the two
            // durations apart without depending on the time of day.
            durationInTrafficSeconds: $query->trafficAware ? (int) round($duration * 1.25) : null,
            providerRouteId: 'mock_route_'.substr(hash('sha256', $query->origin->toKey().$query->destination->toKey()), 0, 12),
        );
    }

    public function matrix(array $origins, array $destinations, TravelMode $travelMode): RouteMatrixResult
    {
        $cells = [];

        foreach ($origins as $i => $origin) {
            foreach ($destinations as $j => $destination) {
                $distance = (int) round(Haversine::metres($origin, $destination) * self::ROAD_FACTOR);

                $cells[$i][$j] = [
                    'distanceMetres' => $distance,
                    'durationSeconds' => (int) round($distance / self::BASE_SPEED_MPS),
                ];
            }
        }

        return new RouteMatrixResult($cells, $this->name());
    }

    public function autocomplete(string $input, ?Coordinates $bias = null, ?string $countryCode = null): array
    {
        if (trim($input) === '') {
            return [];
        }

        if (str_contains(mb_strtolower($input), 'nowhere')) {
            return [];
        }

        return array_map(
            fn (string $suffix): PlaceSuggestion => new PlaceSuggestion(
                description: sprintf('%s %s, Lagos, Nigeria', trim($input), $suffix),
                providerPlaceId: 'mock_place_'.substr(hash('sha256', $input.$suffix), 0, 16),
                mainText: sprintf('%s %s', trim($input), $suffix),
                secondaryText: 'Lagos, Nigeria',
            ),
            ['Street', 'Avenue', 'Close'],
        );
    }

    /**
     * A stable point derived from the query text.
     *
     * Hash-derived rather than random so the same address always resolves to
     * the same place: a test that geocodes twice and compares must get an
     * answer, not a coin toss. Spread across a box around Lagos so distances
     * between distinct addresses are non-trivial.
     */
    private function coordinatesFor(string $seed): Coordinates
    {
        $hash = hash('sha256', ($this->config['seed'] ?? 'eruofood').'|'.$seed);

        $latOffset = (hexdec(substr($hash, 0, 6)) % 20000) / 100000;  // 0 – 0.2°
        $lonOffset = (hexdec(substr($hash, 6, 6)) % 20000) / 100000;

        return new Coordinates(
            round(6.4000 + $latOffset, 7),
            round(3.3000 + $lonOffset, 7),
        );
    }

    private function streetFor(Coordinates $coordinates): string
    {
        $streets = ['Allen Avenue', 'Awolowo Road', 'Adeola Odeku Street', 'Herbert Macaulay Way'];
        $index = (int) hexdec(substr(hash('sha256', $coordinates->toKey()), 0, 4)) % count($streets);

        return $streets[$index];
    }

    private function formatted(string $address): string
    {
        $clean = preg_replace('/\s+/u', ' ', trim($address)) ?? $address;

        return sprintf('%s, Ikeja, Lagos, Nigeria', $clean);
    }
}
