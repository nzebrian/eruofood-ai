<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Application\Service;

use EruoFood\PublicApi\Domain\Read\SearchReadPort;

/**
 * The public search surface. Delegates to the {@see SearchReadPort} over the
 * Search context's own pipeline — the Public API never queries the search index
 * directly. Results, suggestions and the filter catalogue come back as
 * already-shaped arrays (Search owns a rich, presenter-formatted model).
 */
final readonly class PublicSearchService
{
    public function __construct(private SearchReadPort $search)
    {
    }

    /**
     * @param array<string, string> $filters
     *
     * @return array<string, mixed>
     */
    public function query(string $term, ?string $type, array $filters, int $page, int $perPage): array
    {
        return $this->search->query($term, $type, $filters, $page, $perPage);
    }

    /**
     * @return list<string>
     */
    public function suggestions(string $prefix, ?string $type): array
    {
        return $this->search->suggestions($prefix, $type);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function filters(): array
    {
        return $this->search->filters();
    }
}
