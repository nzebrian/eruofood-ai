<?php

declare(strict_types=1);

namespace EruoFood\Search\Infrastructure\Source;

use DateTimeImmutable;
use EruoFood\Search\Domain\Document\DocumentFacets;
use EruoFood\Search\Domain\Document\SearchDocument;
use EruoFood\Search\Domain\Enum\SearchType;

/** Indexes published recipes from the Catalog context (read-only). */
final class RecipeSourceProvider extends AbstractSourceProvider
{
    protected function table(): string
    {
        return 'catalog_recipes';
    }

    protected function baseQuery(): \Illuminate\Database\Query\Builder
    {
        return $this->db->table($this->table())->where('status', 'published')->whereNull('deleted_at');
    }

    public function type(): string
    {
        return 'recipe';
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

        $ingredients = $this->names($row['ingredients'] ?? null);
        $tags = $this->stringList($row['tags'] ?? null);
        $prep = (int) ($row['prep_time_minutes'] ?? 0) + (int) ($row['cook_time_minutes'] ?? 0);

        $facets = new DocumentFacets(
            ingredients: $ingredients,
            dietary: $tags,
            difficulty: isset($row['difficulty']) ? (string) $row['difficulty'] : null,
            popularity: (int) ($row['rating_count'] ?? 0),
            rating: (float) ($row['rating_average'] ?? 0),
            prepTimeMinutes: $prep > 0 ? $prep : null,
        );

        return SearchDocument::create(
            type: SearchType::Recipe,
            sourceId: (string) $row['id'],
            title: (string) ($row['title'] ?? ''),
            description: (string) ($row['summary'] ?? ''),
            keywords: [...$ingredients, ...$tags],
            url: isset($row['slug']) ? '/recipes/'.$row['slug'] : null,
            image: null,
            locale: 'en',
            facets: $facets,
            geo: null,
            now: new DateTimeImmutable(),
        );
    }
}
