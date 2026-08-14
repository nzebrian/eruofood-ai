<?php

declare(strict_types=1);

namespace EruoFood\Geo\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Geo\Domain\Enum\LocationPrecision;
use EruoFood\Geo\Domain\Enum\LocationSource;
use EruoFood\Geo\Domain\Enum\LocationVerificationStatus;
use EruoFood\Geo\Domain\Location\Location;
use EruoFood\Geo\Domain\Location\LocationRepository;
use EruoFood\Geo\Domain\ValueObject\Coordinates;
use EruoFood\Geo\Domain\ValueObject\Haversine;
use EruoFood\Geo\Domain\ValueObject\PostalAddress;
use EruoFood\Geo\Infrastructure\Persistence\Eloquent\Model\GeoLocationModel;
use Illuminate\Support\Str;

/**
 * Eloquent persistence for {@see Location}.
 *
 * The proximity query is the interesting part, and it is deliberately two
 * stages: SQL narrows to a latitude/longitude box using the composite index,
 * then PHP measures the survivors exactly with {@see Haversine}. It is the
 * pattern `EloquentVendorRepository` already uses successfully, and it is what
 * keeps PostGIS a later option rather than a present dependency — the day
 * `ST_DWithin` replaces this, only this method changes.
 */
final class EloquentLocationRepository implements LocationRepository
{
    /**
     * How many box matches to measure exactly.
     *
     * The box cannot be ordered by true distance in SQL, so the exact pass has
     * to see every candidate before it can pick the nearest — which means a
     * `LIMIT` in the query would silently discard closer results. This ceiling
     * exists only so a pathological radius cannot load a table into memory; it
     * is set far above any legitimate `$limit` so it never truncates a real
     * answer. If it is ever reached, the radius was the problem.
     */
    private const MAX_CANDIDATES = 2_000;

    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?Location
    {
        $model = GeoLocationModel::query()->find($id);

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $models = GeoLocationModel::query()->whereIn('id', $ids)->get();

        return array_values(array_map(fn (GeoLocationModel $m): Location => $this->toDomain($m), $models->all()));
    }

    public function withinRadius(Coordinates $centre, float $radiusMetres, int $limit = 50): array
    {
        $box = Haversine::boundingBox($centre, $radiusMetres);

        $models = GeoLocationModel::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereBetween('latitude', [$box['minLat'], $box['maxLat']])
            ->whereBetween('longitude', [$box['minLon'], $box['maxLon']])
            ->limit(self::MAX_CANDIDATES)
            ->get();

        $matches = [];

        foreach ($models as $model) {
            $location = $this->toDomain($model);
            $coordinates = $location->coordinates();

            if ($coordinates === null) {
                continue;
            }

            // The box is generous by construction — its corners sit outside the
            // circle — so the exact measurement is what actually decides.
            $distance = Haversine::metres($centre, $coordinates);

            if ($distance > $radiusMetres) {
                continue;
            }

            $matches[] = ['location' => $location, 'distanceMetres' => $distance];
        }

        usort($matches, static fn (array $a, array $b): int => $a['distanceMetres'] <=> $b['distanceMetres']);

        return array_slice($matches, 0, max(0, $limit));
    }

    public function needingGeocode(int $limit = 100): array
    {
        $models = GeoLocationModel::query()
            ->whereNull('latitude')
            ->whereNotNull('address_text')
            // Oldest first, so a location that has been waiting since the
            // provider outage is picked up before one created a minute ago.
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        return array_values(array_map(fn (GeoLocationModel $m): Location => $this->toDomain($m), $models->all()));
    }

    public function save(Location $location): void
    {
        $address = $location->address();
        $coordinates = $location->coordinates();

        $attributes = [
            'formatted_address' => $address->formatted,
            'line1' => $address->line1,
            'line2' => $address->line2,
            'district' => $address->district,
            'locality' => $address->locality,
            'admin_area' => $address->adminArea,
            'sub_admin_area' => $address->subAdminArea,
            'postal_code' => $address->postalCode,
            'country_code' => $address->countryCode,
            'country_name' => $address->countryName,
            'latitude' => $coordinates?->latitude,
            'longitude' => $coordinates?->longitude,
            'source' => $location->source()->value,
            'precision' => $location->precision()->value,
            'confidence' => $location->precision()->confidence(),
            'verification_status' => $location->status()->value,
            'provider' => $location->provider(),
            'provider_place_id' => $location->providerPlaceId(),
            'geocoded_at' => $location->geocodedAt(),
            'verified_at' => $location->verifiedAt(),
            'updated_at' => $location->updatedAt(),
        ];

        $exists = GeoLocationModel::query()->whereKey($location->id())->exists();

        if (! $exists) {
            GeoLocationModel::query()->insert($attributes + [
                'id' => $location->id(),
                // What was originally entered, written once. A geocode replaces
                // `formatted_address` with the provider's version, and losing
                // the merchant's own words alongside it would leave nobody able
                // to see what they had actually meant.
                'address_text' => $address->displayLine() !== '' ? $address->displayLine() : null,
                'created_at' => $location->createdAt(),
            ]);

            return;
        }

        GeoLocationModel::query()->whereKey($location->id())->update($attributes);
    }

    private function toDomain(GeoLocationModel $m): Location
    {
        return Location::reconstitute(
            id: $m->id,
            address: new PostalAddress(
                formatted: $m->formatted_address ?? $m->address_text,
                line1: $m->line1,
                line2: $m->line2,
                district: $m->district,
                locality: $m->locality,
                adminArea: $m->admin_area,
                subAdminArea: $m->sub_admin_area,
                postalCode: $m->postal_code,
                countryCode: $m->country_code,
                countryName: $m->country_name,
            ),
            // `tryFromMixed` rather than a direct construction: a row that
            // somehow holds an impossible coordinate becomes a location without
            // one, which the domain already knows how to handle, instead of an
            // exception thrown in the middle of a list query.
            coordinates: Coordinates::tryFromMixed($m->latitude, $m->longitude),
            source: LocationSource::from($m->source),
            precision: LocationPrecision::from($m->precision),
            status: LocationVerificationStatus::from($m->verification_status),
            provider: $m->provider,
            providerPlaceId: $m->provider_place_id,
            geocodedAt: $m->geocoded_at !== null ? DateTimeImmutable::createFromInterface($m->geocoded_at) : null,
            verifiedAt: $m->verified_at !== null ? DateTimeImmutable::createFromInterface($m->verified_at) : null,
            createdAt: DateTimeImmutable::createFromInterface($m->created_at),
            updatedAt: DateTimeImmutable::createFromInterface($m->updated_at),
        );
    }
}
