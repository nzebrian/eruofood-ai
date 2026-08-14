<?php

declare(strict_types=1);

namespace EruoFood\Geo\Application\Service;

use EruoFood\Geo\Application\DTO\GeocodeQuery;
use EruoFood\Geo\Application\DTO\GeocodeResult;
use EruoFood\Geo\Application\DTO\PlaceSuggestion;
use EruoFood\Geo\Application\Port\GeoCache;
use EruoFood\Geo\Application\Port\GeoProviderRegistry;
use EruoFood\Geo\Domain\Enum\LocationPrecision;
use EruoFood\Geo\Domain\ValueObject\Coordinates;
use EruoFood\Geo\Domain\ValueObject\PostalAddress;

/**
 * Geocoding, with the cache and the cost controls that make it affordable.
 *
 * The economics are the design here. A geocode costs money every time, and an
 * address's coordinates do not change — so the same address asked twice should
 * cost once, and the second answer should arrive in a millisecond. A month of
 * cache on a fact that is stable for years is conservative.
 *
 * Rounding is what makes reverse geocoding cacheable at all. Two device fixes
 * from the same doorway differ in the sixth decimal place and would otherwise
 * be two different keys for one answer, so the key is built from a rounded
 * point — five decimals, about a metre.
 */
final readonly class GeocodingService
{
    public function __construct(
        private GeoProviderRegistry $providers,
        private GeoCache $cache,
        private ProviderGuard $guard,
        private int $geocodeTtl,
        private int $reverseGeocodeTtl,
        private int $autocompleteTtl,
        private int $cachePrecision,
    ) {
    }

    public function geocode(GeocodeQuery $query): GeocodeResult
    {
        $provider = $this->providers->geocoding($query->countryCode);
        $key = 'geocode:'.hash('sha256', $query->normalised().'|'.($query->language ?? ''));

        $cached = $this->fromCache($key);

        if ($cached !== null) {
            $this->guard->recordCacheHit($provider->name(), 'geocode');

            return $cached;
        }

        $result = $this->guard->call(
            $provider->name(),
            'geocode',
            fn (): GeocodeResult => $provider->geocode($query),
        );

        $this->cache->put($key, $this->toCache($result), $this->geocodeTtl);

        return $result;
    }

    public function reverseGeocode(Coordinates $coordinates, ?string $language = null, ?string $countryCode = null): GeocodeResult
    {
        $provider = $this->providers->geocoding($countryCode);
        $key = 'reverse:'.$coordinates->roundedTo($this->cachePrecision)->toKey($this->cachePrecision).'|'.($language ?? '');

        $cached = $this->fromCache($key);

        if ($cached !== null) {
            $this->guard->recordCacheHit($provider->name(), 'reverse_geocode');

            return $cached;
        }

        $result = $this->guard->call(
            $provider->name(),
            'reverse_geocode',
            fn (): GeocodeResult => $provider->reverseGeocode($coordinates, $language),
        );

        $this->cache->put($key, $this->toCache($result), $this->reverseGeocodeTtl);

        return $result;
    }

    /**
     * Address suggestions as somebody types.
     *
     * The most expensive capability on the platform per useful outcome: a
     * keystroke-per-request client makes twenty billable calls to save one
     * address. Cached hard, and the API layer rate-limits it separately.
     *
     * @return list<PlaceSuggestion>
     */
    public function autocomplete(string $input, ?Coordinates $bias = null, ?string $countryCode = null): array
    {
        $trimmed = trim($input);

        // Two characters suggest nothing useful and cost the same as twenty.
        if (mb_strlen($trimmed) < 3) {
            return [];
        }

        $provider = $this->providers->places($countryCode);
        $key = 'autocomplete:'.hash('sha256', mb_strtolower($trimmed).'|'.($countryCode ?? '').'|'.($bias?->roundedTo(2)->toKey(2) ?? ''));

        $cached = $this->cache->get($key);

        if ($cached !== null) {
            $this->guard->recordCacheHit($provider->name(), 'autocomplete');

            return $this->suggestionsFromCache($cached);
        }

        $suggestions = $this->guard->call(
            $provider->name(),
            'autocomplete',
            fn (): array => $provider->autocomplete($trimmed, $bias, $countryCode),
        );

        $this->cache->put(
            $key,
            array_map(static fn (PlaceSuggestion $s): array => [
                'description' => $s->description,
                'providerPlaceId' => $s->providerPlaceId,
                'mainText' => $s->mainText,
                'secondaryText' => $s->secondaryText,
            ], $suggestions),
            $this->autocompleteTtl,
        );

        return $suggestions;
    }

    private function fromCache(string $key): ?GeocodeResult
    {
        $cached = $this->cache->get($key);

        if ($cached === null) {
            return null;
        }

        $coordinates = Coordinates::tryFromMixed($cached['latitude'] ?? null, $cached['longitude'] ?? null);

        // A cache entry written by an older release may not deserialise. Treat
        // that as a miss rather than an error: the cost is one provider call,
        // and the alternative is a deployment that fails on warm cache.
        if ($coordinates === null || ! is_array($cached['address'] ?? null) || ! is_string($cached['provider'] ?? null)) {
            return null;
        }

        return new GeocodeResult(
            $coordinates,
            PostalAddress::fromArray($cached['address']),
            LocationPrecision::tryFrom((string) ($cached['precision'] ?? '')) ?? LocationPrecision::Unknown,
            $cached['provider'],
            isset($cached['providerPlaceId']) && is_string($cached['providerPlaceId']) ? $cached['providerPlaceId'] : null,
        );
    }

    /** @return array<string, mixed> */
    private function toCache(GeocodeResult $result): array
    {
        return [
            'latitude' => $result->coordinates->latitude,
            'longitude' => $result->coordinates->longitude,
            'address' => $result->address->toArray(),
            'precision' => $result->precision->value,
            'provider' => $result->provider,
            'providerPlaceId' => $result->providerPlaceId,
        ];
    }

    /**
     * @param array<mixed> $cached
     * @return list<PlaceSuggestion>
     */
    private function suggestionsFromCache(array $cached): array
    {
        $suggestions = [];

        foreach ($cached as $entry) {
            if (! is_array($entry) || ! is_string($entry['description'] ?? null) || ! is_string($entry['providerPlaceId'] ?? null)) {
                continue;
            }

            $suggestions[] = new PlaceSuggestion(
                $entry['description'],
                $entry['providerPlaceId'],
                is_string($entry['mainText'] ?? null) ? $entry['mainText'] : null,
                is_string($entry['secondaryText'] ?? null) ? $entry['secondaryText'] : null,
            );
        }

        return $suggestions;
    }
}
