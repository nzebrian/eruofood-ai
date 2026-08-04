<?php

declare(strict_types=1);

namespace EruoFood\Search\Infrastructure\Source;

use DateTimeImmutable;
use EruoFood\Search\Domain\Document\DocumentFacets;
use EruoFood\Search\Domain\Document\SearchDocument;
use EruoFood\Search\Domain\Enum\SearchType;

/** Indexes published products from the Commerce context (read-only). */
final class ProductSourceProvider extends AbstractSourceProvider
{
    protected function table(): string
    {
        return 'commerce_products';
    }

    protected function baseQuery(): \Illuminate\Database\Query\Builder
    {
        return $this->db->table($this->table())->where('status', 'published');
    }

    public function type(): string
    {
        return 'product';
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

        $tags = $this->stringList($row['tags'] ?? null);

        $facets = new DocumentFacets(
            cuisine: isset($row['department']) ? (string) $row['department'] : null,
            category: isset($row['category_id']) ? (string) $row['category_id'] : null,
            dietary: $tags,
            popularity: (int) ($row['rating_count'] ?? 0),
            rating: (float) ($row['rating_average'] ?? 0),
            priceMinor: isset($row['base_price_minor']) ? (int) $row['base_price_minor'] : null,
            vendorId: isset($row['store_id']) ? (string) $row['store_id'] : null,
        );

        $brand = isset($row['brand']) ? (string) $row['brand'] : '';

        return SearchDocument::create(
            type: SearchType::Product,
            sourceId: (string) $row['id'],
            title: (string) ($row['name'] ?? ''),
            description: (string) ($row['description'] ?? ''),
            keywords: array_values(array_filter([$brand, ...$tags], static fn (string $v): bool => $v !== '')),
            url: isset($row['slug']) ? '/shop/'.$row['slug'] : null,
            image: $this->firstImage($row['images'] ?? null),
            locale: 'en',
            facets: $facets,
            geo: null,
            now: new DateTimeImmutable(),
        );
    }
}
