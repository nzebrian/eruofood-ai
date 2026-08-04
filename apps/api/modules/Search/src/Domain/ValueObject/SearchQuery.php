<?php

declare(strict_types=1);

namespace EruoFood\Search\Domain\ValueObject;

use EruoFood\Search\Domain\Enum\SearchType;
use EruoFood\Search\Domain\Enum\SortOption;
use EruoFood\Search\Domain\Exception\SearchInvalidQuery;

/**
 * A fully-normalised search request — the value the {@see \EruoFood\Search\Domain\Document\SearchIndexRepository}
 * executes. The raw user input is turned into one of these by the QueryBuilder
 * (normalisation, synonym expansion, filter parsing); the domain and
 * infrastructure only ever see this validated shape.
 */
final readonly class SearchQuery
{
    /**
     * @param list<string> $expandedTerms the normalised term plus any synonyms
     */
    public function __construct(
        public string $term,
        public array $expandedTerms,
        public SearchType $type,
        public SearchFilters $filters,
        public SortOption $sort,
        public int $page,
        public int $perPage,
        public string $locale = 'en',
        public ?GeoPoint $geo = null,
    ) {
        if ($sort->requiresGeo() && $geo === null) {
            throw new SearchInvalidQuery('Distance sorting requires a location.');
        }
        if ($page < 1 || $perPage < 1) {
            throw new SearchInvalidQuery('Pagination is out of range.');
        }
    }

    public function hasTerm(): bool
    {
        return trim($this->term) !== '';
    }

    /** The full set of lexical terms to match (deduplicated, lower-cased). */
    /** @return list<string> */
    public function lexicalTerms(): array
    {
        $terms = array_map(
            static fn (string $t): string => mb_strtolower(trim($t)),
            [$this->term, ...$this->expandedTerms],
        );

        return array_values(array_unique(array_filter($terms, static fn (string $t): bool => $t !== '')));
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /** A deterministic cache key fragment for this query. */
    public function cacheKey(): string
    {
        return md5(implode('|', [
            implode(',', $this->lexicalTerms()),
            $this->type->value,
            $this->sort->value,
            $this->page,
            $this->perPage,
            $this->locale,
            $this->geo !== null ? round($this->geo->latitude, 3).':'.round($this->geo->longitude, 3) : '',
            json_encode($this->filters->toArray()),
        ]));
    }
}
