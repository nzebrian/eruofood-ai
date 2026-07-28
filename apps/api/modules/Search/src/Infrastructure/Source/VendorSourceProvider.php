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
        $lat = $row['latitude'] ?? null;
        $lng = $row['longitude'] ?? null;
        $geo = ($lat !== null && $lng !== null) ? new GeoPoint((float) $lat, (float) $lng) : null;

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
}
