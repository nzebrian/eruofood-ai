<?php

declare(strict_types=1);

namespace EruoFood\Geo\Infrastructure\Provider\Google;

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
use EruoFood\Geo\Domain\Exception\GeoQuotaExceeded;
use EruoFood\Geo\Domain\Route\Route;
use EruoFood\Geo\Domain\ValueObject\Coordinates;
use EruoFood\Geo\Domain\ValueObject\PostalAddress;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Google Maps, as one interchangeable adapter among possible others.
 *
 * Nothing above this class names Google. It implements the same four ports the
 * mock does, is selected by a line of configuration, and translates Google's
 * vocabulary into the platform's on the way in. Replacing it with Mapbox or a
 * regional provider is a new class and a config edit — no service, controller
 * or domain object moves.
 *
 * ## Two things here are security-relevant
 *
 * **The key never travels in a URL.** It goes in the `X-Goog-Api-Key` header on
 * the Routes and Places calls, and for the older Geocoding API — which accepts
 * it only as a query parameter — the request is built so the key is never
 * interpolated into a string that could be logged. Query strings end up in
 * access logs, proxy logs and exception traces; headers generally do not.
 *
 * **No provider message is ever re-thrown.** Google's errors quote the request,
 * and for geocoding the request *is* somebody's home address; a `REQUEST_DENIED`
 * body can also name the quota project. Every failure is translated into a
 * platform exception carrying a normalised code and nothing else.
 */
final readonly class GoogleMapsProvider implements DistanceMatrixProvider, GeocodingProvider, PlacesProvider, RoutingProvider
{
    /**
     * Ask only for the fields actually used.
     *
     * The Routes API bills by response tier, so requesting the full route
     * object — legs, steps, turn-by-turn instructions — costs materially more
     * than requesting distance and duration. This is the cheap tier plus the
     * polyline, which a map view needs.
     */
    private const ROUTE_FIELD_MASK = 'routes.distanceMeters,routes.duration,routes.staticDuration,routes.polyline.encodedPolyline';

    private const MATRIX_FIELD_MASK = 'originIndex,destinationIndex,distanceMeters,duration,condition';

    /** @param array<string, mixed> $config */
    public function __construct(
        private HttpFactory $http,
        private array $config,
    ) {
    }

    public function name(): string
    {
        return 'google';
    }

    // ------------------------------------------------------------- geocoding

    public function geocode(GeocodeQuery $query): GeocodeResult
    {
        $parameters = ['address' => $query->address];

        // A region bias tilts ambiguous results towards the launch market
        // without excluding anywhere else: "Ikeja" should resolve to Lagos
        // rather than to a similarly-named place on another continent.
        if ($query->countryCode !== null) {
            $parameters['components'] = 'country:'.strtoupper($query->countryCode);
        } elseif ($this->stringConfig('region_bias') !== null) {
            $parameters['region'] = $this->stringConfig('region_bias');
        }

        if ($query->bias !== null) {
            $parameters['bounds'] = $this->boundsAround($query->bias);
        }

        $parameters['language'] = $query->language ?? $this->stringConfig('language') ?? 'en';

        $payload = $this->getJson($this->url('geocoding_url'), $parameters);

        $this->assertGeocodingStatus($payload);

        $result = $payload['results'][0] ?? null;

        if (! is_array($result)) {
            throw GeoAddressNotFound::forQuery();
        }

        return $this->toGeocodeResult($result);
    }

    public function reverseGeocode(Coordinates $coordinates, ?string $language = null): GeocodeResult
    {
        $payload = $this->getJson($this->url('geocoding_url'), [
            'latlng' => $coordinates->toKey(7),
            'language' => $language ?? $this->stringConfig('language') ?? 'en',
        ]);

        try {
            $this->assertGeocodingStatus($payload);
        } catch (GeoAddressNotFound) {
            throw GeoAddressNotFound::forCoordinates();
        }

        $result = $payload['results'][0] ?? null;

        if (! is_array($result)) {
            throw GeoAddressNotFound::forCoordinates();
        }

        return $this->toGeocodeResult($result);
    }

    // --------------------------------------------------------------- routing

    public function route(RouteQuery $query): Route
    {
        $trafficAware = $query->trafficAware && $query->travelMode->isTrafficSensitive();

        $body = [
            'origin' => $this->waypoint($query->origin),
            'destination' => $this->waypoint($query->destination),
            'travelMode' => $this->travelMode($query->travelMode),
            // `TRAFFIC_AWARE` is the middle tier: live conditions without the
            // latency and cost of `TRAFFIC_AWARE_OPTIMAL`, which is built for
            // long-horizon planning rather than a checkout quote.
            'routingPreference' => $trafficAware ? 'TRAFFIC_AWARE' : 'TRAFFIC_UNAWARE',
            'languageCode' => $this->stringConfig('language') ?? 'en',
            'units' => 'METRIC',
        ];

        $payload = $this->postJson($this->url('routes_url'), $body, self::ROUTE_FIELD_MASK);

        $route = $payload['routes'][0] ?? null;

        if (! is_array($route) || ! isset($route['distanceMeters'])) {
            // Reached Google, and there is genuinely no road between these
            // points — a lagoon, an island, a closed border. Not an outage.
            throw GeoProviderUnavailable::because('No route exists between those points.');
        }

        $duration = $this->seconds($route['staticDuration'] ?? $route['duration'] ?? null);
        $trafficDuration = $trafficAware ? $this->seconds($route['duration'] ?? null) : null;

        return new Route(
            origin: $query->origin,
            destination: $query->destination,
            distanceMetres: (int) $route['distanceMeters'],
            durationSeconds: $duration,
            travelMode: $query->travelMode,
            source: RouteSource::Provider,
            provider: $this->name(),
            calculatedAt: new DateTimeImmutable(),
            durationInTrafficSeconds: $trafficDuration,
            providerRouteId: null,
            polyline: $this->polyline($route),
        );
    }

    public function matrix(array $origins, array $destinations, TravelMode $travelMode): RouteMatrixResult
    {
        $payload = $this->postJson(
            $this->url('matrix_url'),
            [
                // Sent in order, and Google's `originIndex` refers to that
                // order — which is why the port contracts a list.
                'origins' => array_map(fn (Coordinates $c): array => ['waypoint' => $this->waypoint($c)], $origins),
                'destinations' => array_map(fn (Coordinates $c): array => ['waypoint' => $this->waypoint($c)], $destinations),
                'travelMode' => $this->travelMode($travelMode),
                'routingPreference' => 'TRAFFIC_UNAWARE',
                'units' => 'METRIC',
            ],
            self::MATRIX_FIELD_MASK,
        );

        $cells = [];

        foreach ($payload as $element) {
            if (! is_array($element) || ! isset($element['originIndex'], $element['destinationIndex'])) {
                continue;
            }

            // A matrix reports per-pair failures inline rather than failing the
            // whole call. An unroutable pair is simply absent from the result,
            // so a caller iterating cells cannot mistake it for a zero.
            if (! isset($element['distanceMeters']) || ($element['condition'] ?? 'ROUTE_EXISTS') !== 'ROUTE_EXISTS') {
                continue;
            }

            $cells[(int) $element['originIndex']][(int) $element['destinationIndex']] = [
                'distanceMetres' => (int) $element['distanceMeters'],
                'durationSeconds' => $this->seconds($element['duration'] ?? null),
            ];
        }

        return new RouteMatrixResult($cells, $this->name());
    }

    // ---------------------------------------------------------------- places

    public function autocomplete(string $input, ?Coordinates $bias = null, ?string $countryCode = null): array
    {
        if (trim($input) === '') {
            return [];
        }

        $body = ['input' => trim($input), 'languageCode' => $this->stringConfig('language') ?? 'en'];

        if ($countryCode !== null) {
            $body['includedRegionCodes'] = [strtolower($countryCode)];
        }

        if ($bias !== null) {
            $body['locationBias'] = [
                'circle' => [
                    'center' => ['latitude' => $bias->latitude, 'longitude' => $bias->longitude],
                    'radiusMeters' => 50_000.0,
                ],
            ];
        }

        $payload = $this->postJson($this->url('places_url'), $body, null);

        $suggestions = [];

        foreach ($payload['suggestions'] ?? [] as $suggestion) {
            $prediction = $suggestion['placePrediction'] ?? null;

            if (! is_array($prediction) || ! isset($prediction['placeId'])) {
                continue;
            }

            $suggestions[] = new PlaceSuggestion(
                description: (string) ($prediction['text']['text'] ?? ''),
                providerPlaceId: (string) $prediction['placeId'],
                mainText: isset($prediction['structuredFormat']['mainText']['text'])
                    ? (string) $prediction['structuredFormat']['mainText']['text']
                    : null,
                secondaryText: isset($prediction['structuredFormat']['secondaryText']['text'])
                    ? (string) $prediction['structuredFormat']['secondaryText']['text']
                    : null,
            );
        }

        return $suggestions;
    }

    // ----------------------------------------------------------- translation

    /**
     * @param array<string, mixed> $result
     */
    private function toGeocodeResult(array $result): GeocodeResult
    {
        $location = $result['geometry']['location'] ?? [];

        $coordinates = Coordinates::tryFromMixed($location['lat'] ?? null, $location['lng'] ?? null);

        if ($coordinates === null) {
            // A result with no usable point is not a result. Treating it as one
            // would store a location that silently fails every distance check.
            throw GeoAddressNotFound::forQuery();
        }

        return new GeocodeResult(
            $coordinates,
            $this->toPostalAddress($result),
            $this->precision((string) ($result['geometry']['location_type'] ?? '')),
            $this->name(),
            isset($result['place_id']) ? (string) $result['place_id'] : null,
        );
    }

    /**
     * Google's address components, mapped onto country-neutral names.
     *
     * `administrative_area_level_1` is a state in Nigeria, a province in Kenya
     * and a county in the UK; level 2 is an LGA here and a district elsewhere.
     * The platform's own vocabulary stays generic so the domain does not
     * inherit one country's civil geography.
     *
     * @param array<string, mixed> $result
     */
    private function toPostalAddress(array $result): PostalAddress
    {
        $components = [];

        foreach ($result['address_components'] ?? [] as $component) {
            if (! is_array($component)) {
                continue;
            }

            foreach ($component['types'] ?? [] as $type) {
                $components[(string) $type] ??= [
                    'long' => (string) ($component['long_name'] ?? ''),
                    'short' => (string) ($component['short_name'] ?? ''),
                ];
            }
        }

        $long = static fn (string $type): ?string => $components[$type]['long'] ?? null;
        $short = static fn (string $type): ?string => $components[$type]['short'] ?? null;

        $streetNumber = $long('street_number');
        $route = $long('route');

        $countryCode = $short('country');

        return new PostalAddress(
            formatted: isset($result['formatted_address']) ? (string) $result['formatted_address'] : null,
            line1: trim(implode(' ', array_filter([$streetNumber, $route]))) ?: null,
            line2: $long('subpremise'),
            // Nigerian addresses lean on the neighbourhood far more than on the
            // street, so the sublocality is kept when there is no named
            // neighbourhood.
            district: $long('neighborhood') ?? $long('sublocality') ?? $long('sublocality_level_1'),
            locality: $long('locality') ?? $long('postal_town'),
            adminArea: $long('administrative_area_level_1'),
            subAdminArea: $long('administrative_area_level_2'),
            postalCode: $long('postal_code'),
            countryCode: $countryCode === null ? null : strtoupper($countryCode),
            countryName: $long('country'),
        );
    }

    private function precision(string $locationType): LocationPrecision
    {
        return match ($locationType) {
            'ROOFTOP' => LocationPrecision::Rooftop,
            'RANGE_INTERPOLATED' => LocationPrecision::RangeInterpolated,
            'GEOMETRIC_CENTER' => LocationPrecision::GeometricCentre,
            'APPROXIMATE' => LocationPrecision::Approximate,
            default => LocationPrecision::Unknown,
        };
    }

    private function travelMode(TravelMode $mode): string
    {
        return match ($mode) {
            TravelMode::Driving => 'DRIVE',
            TravelMode::TwoWheeler => 'TWO_WHEELER',
            TravelMode::Bicycle => 'BICYCLE',
            TravelMode::Walking => 'WALK',
        };
    }

    /** @return array<string, mixed> */
    private function waypoint(Coordinates $coordinates): array
    {
        return ['location' => ['latLng' => ['latitude' => $coordinates->latitude, 'longitude' => $coordinates->longitude]]];
    }

    /** Durations arrive as protobuf strings — "1234s". */
    private function seconds(mixed $duration): int
    {
        if (is_int($duration) || is_float($duration)) {
            return (int) round((float) $duration);
        }

        if (is_string($duration) && preg_match('/^(\d+(?:\.\d+)?)s$/', $duration, $matches) === 1) {
            return (int) round((float) $matches[1]);
        }

        return 0;
    }

    /** @param array<string, mixed> $route */
    private function polyline(array $route): ?string
    {
        $encoded = $route['polyline']['encodedPolyline'] ?? null;

        return is_string($encoded) && $encoded !== '' ? $encoded : null;
    }

    /**
     * A small box around a point, for biasing an ambiguous geocode.
     *
     * Roughly 25 km each way. Wide enough to cover a metropolitan area, narrow
     * enough that a bias actually biases.
     */
    private function boundsAround(Coordinates $centre): string
    {
        $delta = 0.25;

        return sprintf(
            '%F,%F|%F,%F',
            max(-90.0, $centre->latitude - $delta),
            max(-180.0, $centre->longitude - $delta),
            min(90.0, $centre->latitude + $delta),
            min(180.0, $centre->longitude + $delta),
        );
    }

    // -------------------------------------------------------------- transport

    /**
     * @param array<string, mixed> $parameters
     * @return array<mixed>
     */
    private function getJson(string $url, array $parameters): array
    {
        // The key is passed as a request parameter, never concatenated into the
        // URL string, so it cannot be captured by anything that logs the URL it
        // was handed.
        return $this->send(fn (): Response => $this->client()->get($url, $parameters + ['key' => $this->serverKey()]));
    }

    /**
     * @param array<string, mixed> $body
     * @return array<mixed>
     */
    private function postJson(string $url, array $body, ?string $fieldMask): array
    {
        $headers = ['X-Goog-Api-Key' => $this->serverKey()];

        if ($fieldMask !== null) {
            $headers['X-Goog-FieldMask'] = $fieldMask;
        }

        return $this->send(fn (): Response => $this->client()->withHeaders($headers)->post($url, $body));
    }

    /**
     * Issue the request and normalise every possible outcome.
     *
     * The one place a provider failure becomes a platform failure. Nothing
     * Google says is passed through: its error bodies quote the request, and
     * for a geocode the request is somebody's home address.
     *
     * @param callable(): Response $request
     * @return array<mixed>
     */
    private function send(callable $request): array
    {
        try {
            $response = $request();
        } catch (ConnectionException) {
            // Timeout, DNS failure, refused connection — ours to absorb, and
            // the fallback chain's cue.
            throw GeoProviderUnavailable::because('The mapping provider could not be reached.');
        } catch (Throwable) {
            throw GeoProviderUnavailable::because('The mapping request could not be completed.');
        }

        if ($response->status() === 429) {
            throw GeoQuotaExceeded::forPlatform();
        }

        if ($response->status() === 403 || $response->status() === 401) {
            // Almost always a misconfigured or restricted key. Distinguished in
            // telemetry by code, never by echoing a body that can name the
            // quota project.
            throw GeoProviderUnavailable::because('The mapping provider rejected the request.');
        }

        if ($response->failed()) {
            throw GeoProviderUnavailable::because('The mapping provider returned an error.');
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw GeoProviderUnavailable::because('The mapping provider returned an unreadable response.');
        }

        return $payload;
    }

    /**
     * The Geocoding API reports failure in a 200 body, not in the status code.
     *
     * Missing this is the classic integration bug: `ZERO_RESULTS` arrives as
     * HTTP 200 and, unchecked, becomes an empty address that reads as success.
     *
     * @param array<mixed> $payload
     */
    private function assertGeocodingStatus(array $payload): void
    {
        $status = (string) ($payload['status'] ?? 'UNKNOWN_ERROR');

        match ($status) {
            'OK' => null,
            'ZERO_RESULTS' => throw GeoAddressNotFound::forQuery(),
            'OVER_QUERY_LIMIT', 'OVER_DAILY_LIMIT' => throw GeoQuotaExceeded::forPlatform(),
            'REQUEST_DENIED' => throw GeoProviderUnavailable::because('The mapping provider rejected the request.'),
            'INVALID_REQUEST' => throw GeoAddressNotFound::forQuery(),
            default => throw GeoProviderUnavailable::because('The mapping provider returned an error.'),
        };
    }

    private function client(): \Illuminate\Http\Client\PendingRequest
    {
        return $this->http
            ->timeout($this->intConfig('timeout_seconds', 8))
            // A retry helps with a dropped connection and hurts with a rejected
            // key, so only connection-level failures are retried. Retrying a
            // 4xx would multiply the bill without changing the answer.
            ->retry(
                max(1, $this->intConfig('retry_attempts', 2)),
                $this->intConfig('retry_delay_ms', 250),
                static fn (Throwable $e): bool => $e instanceof ConnectionException,
                throw: false,
            )
            ->acceptJson();
    }

    private function serverKey(): string
    {
        $key = $this->stringConfig('server_key');

        if ($key === null || $key === '') {
            // Raised rather than sent unauthenticated, which would produce a
            // `REQUEST_DENIED` that looks like an outage instead of a
            // deployment that was never configured.
            throw GeoProviderUnavailable::because('The mapping provider is not configured.');
        }

        return $key;
    }

    private function url(string $key): string
    {
        $url = $this->stringConfig($key);

        if ($url === null || $url === '') {
            throw GeoProviderUnavailable::because('The mapping provider is not configured.');
        }

        return $url;
    }

    private function stringConfig(string $key): ?string
    {
        $value = $this->config[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function intConfig(string $key, int $default): int
    {
        $value = $this->config[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }
}
