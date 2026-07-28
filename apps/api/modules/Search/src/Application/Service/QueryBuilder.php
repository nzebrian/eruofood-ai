<?php

declare(strict_types=1);

namespace EruoFood\Search\Application\Service;

use EruoFood\Search\Application\Port\QueryUnderstanding;
use EruoFood\Search\Domain\Enum\SearchType;
use EruoFood\Search\Domain\Enum\SortOption;
use EruoFood\Search\Domain\ValueObject\GeoPoint;
use EruoFood\Search\Domain\ValueObject\SearchFilters;
use EruoFood\Search\Domain\ValueObject\SearchQuery;

/**
 * Turns raw request parameters into a normalised {@see SearchQuery}: it
 * lower-cases and trims the term, expands it with synonym groups (so "jollof"
 * also matches "party rice") and — when enabled — with AI query understanding,
 * and clamps pagination to safe bounds. Everything downstream sees only the
 * validated query.
 */
final readonly class QueryBuilder
{
    /**
     * @param list<list<string>> $synonymGroups
     */
    public function __construct(
        private QueryUnderstanding $understanding,
        private array $synonymGroups,
        private int $defaultPerPage,
        private int $maxPerPage,
        private bool $aiUnderstanding,
    ) {
    }

    public function build(
        string $term,
        SearchType $type,
        SearchFilters $filters,
        SortOption $sort,
        int $page,
        int $perPage,
        string $locale,
        ?GeoPoint $geo,
    ): SearchQuery {
        $normalized = mb_strtolower(trim($term));
        $perPage = $perPage <= 0 ? $this->defaultPerPage : min($perPage, $this->maxPerPage);
        $page = max(1, $page);

        $expanded = $this->expand($normalized, $locale);

        return new SearchQuery(
            term: $normalized,
            expandedTerms: $expanded,
            type: $type,
            filters: $filters,
            sort: $sort,
            page: $page,
            perPage: $perPage,
            locale: $locale,
            geo: $geo,
        );
    }

    /**
     * @return list<string>
     */
    private function expand(string $term, string $locale): array
    {
        if ($term === '') {
            return [];
        }

        $terms = [];
        foreach ($this->synonymGroups as $group) {
            $lower = array_map(static fn (string $t): string => mb_strtolower($t), $group);
            if ($this->groupMatchesTerm($lower, $term)) {
                foreach ($lower as $synonym) {
                    if ($synonym !== $term) {
                        $terms[] = $synonym;
                    }
                }
            }
        }

        if ($this->aiUnderstanding) {
            foreach ($this->understanding->expand($term, $locale) as $extra) {
                $extra = mb_strtolower(trim($extra));
                if ($extra !== '' && $extra !== $term) {
                    $terms[] = $extra;
                }
            }
        }

        return array_values(array_unique($terms));
    }

    /**
     * @param list<string> $group
     */
    private function groupMatchesTerm(array $group, string $term): bool
    {
        foreach ($group as $synonym) {
            if ($synonym === $term || str_contains($term, $synonym)) {
                return true;
            }
        }

        return false;
    }
}
