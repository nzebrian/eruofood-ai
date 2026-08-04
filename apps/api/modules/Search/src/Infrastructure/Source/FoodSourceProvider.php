<?php

declare(strict_types=1);

namespace EruoFood\Search\Infrastructure\Source;

use DateTimeImmutable;
use EruoFood\Search\Domain\Document\DocumentFacets;
use EruoFood\Search\Domain\Document\SearchDocument;
use EruoFood\Search\Domain\Enum\SearchType;

/** Indexes published Nigerian foods from the Catalog context (read-only). */
final class FoodSourceProvider extends AbstractSourceProvider
{
    protected function table(): string
    {
        return 'catalog_foods';
    }

    protected function baseQuery(): \Illuminate\Database\Query\Builder
    {
        return $this->db->table($this->table())->where('status', 'published')->whereNull('deleted_at');
    }

    public function type(): string
    {
        return 'food';
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

        $states = $this->stringList($row['states'] ?? null);
        $localNames = $this->stringList($row['local_names'] ?? null);
        $tags = $this->stringList($row['tags'] ?? null);
        $nutrition = $this->decode($row['nutrition'] ?? null);

        $facets = new DocumentFacets(
            region: isset($row['region']) ? (string) $row['region'] : null,
            states: $states,
            category: isset($row['category_id']) ? (string) $row['category_id'] : null,
            dietary: $tags,
            calories: isset($nutrition['calories']) && is_numeric($nutrition['calories']) ? (int) $nutrition['calories'] : null,
        );

        return SearchDocument::create(
            type: SearchType::Food,
            sourceId: (string) $row['id'],
            title: (string) ($row['name'] ?? ''),
            description: (string) ($row['description'] ?? ''),
            keywords: [...$localNames, ...$tags],
            url: isset($row['slug']) ? '/foods/'.$row['slug'] : null,
            image: $this->firstImage($row['images'] ?? null),
            locale: 'en',
            facets: $facets,
            geo: null,
            now: new DateTimeImmutable(),
        );
    }
}
