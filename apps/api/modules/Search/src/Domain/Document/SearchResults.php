<?php

declare(strict_types=1);

namespace EruoFood\Search\Domain\Document;

/**
 * A page of ranked hits plus the total match count and the facet counts used to
 * drive the results-page filter sidebar (e.g. how many matches per region).
 */
final readonly class SearchResults
{
    /**
     * @param list<SearchHit> $hits
     * @param array<string, array<string, int>> $facets  facet name => (value => count)
     */
    public function __construct(
        public array $hits,
        public int $total,
        public int $page,
        public int $perPage,
        public array $facets = [],
    ) {
    }

    public static function empty(int $page, int $perPage): self
    {
        return new self([], 0, $page, $perPage, []);
    }

    public function isEmpty(): bool
    {
        return $this->hits === [];
    }
}
