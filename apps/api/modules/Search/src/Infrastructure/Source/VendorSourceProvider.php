<?php

declare(strict_types=1);

namespace EruoFood\Search\Infrastructure\Source;

use DateTimeImmutable;
use EruoFood\Search\Domain\Document\DocumentFacets;
use EruoFood\Search\Domain\Document\SearchDocument;
use EruoFood\Search\Domain\Enum\SearchType;
use EruoFood\Search\Domain\ValueObject\GeoPoint;

/**
 * Indexes verified vendors/restaurants from the Marketplace context (read-only).
 * A row whose `type` is "restaurant" is indexed as a restaurant document; every
 * other verified trader as a vendor — so the Restaurant and Vendor search scopes
 * stay distinct while sharing one source.
 */
final class VendorSourceProvider extends AbstractSourceProvider
{
    protected function table(): string
    {
        return 'marketplace_vendors';
    }

    protected function baseQuery(): \Illuminate\Database\Query\Builder
    {
        return $this->db->table($this->table())->whereIn('status', ['active', 'verified']);
    }

    public function type(): string
    {
        return 'vendor';
    }

    public function fetch(string $sourceId): ?SearchDocument
    {
        if (! $this->available()) {
            return null;
        }
        $row = (array) ($this->baseQuery()->where('id', $sourceId)->first() ?? []);
        if ($row === []) {
            return null;
        }

        $isRestaurant = (string) ($row['type'] ?? 'vendor') === 'restaurant';
        $geo = $this->geoFor($row);

        $facets = new DocumentFacets(
            category: isset($row['category']) ? (string) $row['category'] : null,
            popularity: (int) ($row['rating_count'] ?? 0),
            rating: (float) ($row['rating_average'] ?? 0),
            restaurantId: $isRestaurant ? (string) $row['id'] : null,
            vendorId: $isRestaurant ? null : (string) $row['id'],
        );

        return SearchDocument::create(
            type: $isRestaurant ? SearchType::Restaurant : SearchType::Vendor,
            sourceId: (string) $row['id'],
            title: (string) ($row['name'] ?? ''),
            description: (string) ($row['description'] ?? ''),
            keywords: array_values(array_filter([
                (string) ($row['type'] ?? ''),
                (string) ($row['category'] ?? ''),
            ], static fn (string $v): bool => $v !== '')),
            url: isset($row['slug']) ? '/vendors/'.$row['slug'] : null,
            image: $this->firstImage($row['images'] ?? null),
            locale: 'en',
            facets: $facets,
            geo: $geo,
            now: new DateTimeImmutable(),
        );
    }

    /**
     * The point to index a merchant at.
     *
     * Prefers the canonical `geo_locations` record M25 introduced, falling back
     * to the latitude/longitude columns that lived on the vendor row before it.
     * The preference matters because the canonical record is the one a merchant
     * actually curates — geocoded, and confirmable by a human who dragged the
     * pin — while the legacy columns were populated once and never revisited.
     *
     * Delegation rather than duplication: Search reads the coordinates through
     * this seam and does its own distance arithmetic with the platform's single
     * {@see \EruoFood\Geo\Domain\ValueObject\Haversine}, so there is no
     * second copy of either the data or the formula.
     *
     * An unusable location — never geocoded, or disputed — is skipped rather
     * than indexed, because a search result placed in the wrong street is worse
     * than one with no distance at all.
     *
     * @param array<string, mixed> $row
     */
    private function geoFor(array $row): ?GeoPoint
    {
        $locationId = $row['primary_location_id'] ?? null;

        if (is_string($locationId) && $locationId !== '') {
            $location = $this->db->table('geo_locations')
                ->where('id', $locationId)
                ->whereNotNull('latitude')
                ->whereIn('verification_status', ['geocoded', 'confirmed'])
                ->first();

            if ($location !== null) {
                return new GeoPoint((float) $location->latitude, (float) $location->longitude);
            }
        }

        $lat = $row['latitude'] ?? null;
        $lng = $row['longitude'] ?? null;

        return ($lat !== null && $lng !== null) ? new GeoPoint((float) $lat, (float) $lng) : null;
    }
}
