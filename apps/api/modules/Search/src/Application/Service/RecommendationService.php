<?php

declare(strict_types=1);

namespace EruoFood\Search\Application\Service;

use EruoFood\Search\Application\Port\EmbeddingGenerator;
use EruoFood\Search\Domain\Access\SearchScopeGate;
use EruoFood\Search\Domain\Analytics\SearchAnalyticsRepository;
use EruoFood\Search\Domain\Document\SearchDocument;
use EruoFood\Search\Domain\Document\SearchHit;
use EruoFood\Search\Domain\Document\SearchIndexRepository;
use EruoFood\Search\Domain\Enum\RecommendationType;
use EruoFood\Search\Domain\Enum\SearchType;
use EruoFood\Search\Domain\Enum\SortOption;
use EruoFood\Search\Domain\ValueObject\SearchFilters;
use EruoFood\Search\Domain\ValueObject\SearchQuery;

/**
 * The recommendation engine. Strategies:
 *   - content-based (vector similarity) for related / similar / frequently-
 *     viewed-together (anchored on a document's embedding);
 *   - popularity for restaurant / seasonal / trending (index popularity, a
 *     proxy that upgrades transparently once behavioural signals accrue);
 *   - personalised, which searches the user's most recent term and falls back
 *     to popularity on a cold start.
 *
 * All of it reads only the Search index — no business module is consulted.
 */
final readonly class RecommendationService
{
    public function __construct(
        private SearchIndexRepository $index,
        private SearchAnalyticsRepository $analytics,
        private EmbeddingGenerator $embedder,
        private SearchScopeGate $gate = new SearchScopeGate(),
    ) {
    }

    /**
     * @return list<SearchDocument>
     */
    public function recommend(
        RecommendationType $kind,
        SearchType $type,
        ?string $anchorId,
        ?string $userId,
        int $limit,
        bool $isAdmin = false,
    ): array {
        // M38-SEC-001. Recommendations read the same index as search and this
        // route is public, so it goes through the same gate. `similarTo` and
        // `popular` are reached from here, which is why the check sits at the
        // entry point rather than on each branch.
        $this->gate->authorize($type, $isAdmin);

        return match ($kind) {
            RecommendationType::Related,
            RecommendationType::Similar,
            RecommendationType::FrequentlyViewedTogether => $this->contentBased($type, $anchorId, $limit),
            RecommendationType::Restaurant => $this->index->popular(SearchType::Vendor, $limit),
            RecommendationType::Personalised => $this->personalised($type, $userId, $limit),
            RecommendationType::Seasonal,
            RecommendationType::Trending => $this->index->popular($type, $limit),
        };
    }

    /**
     * @return list<SearchDocument>
     */
    private function contentBased(SearchType $type, ?string $anchorId, int $limit): array
    {
        if ($anchorId === null) {
            return $this->index->popular($type, $limit);
        }
        $anchor = $this->index->find(SearchDocument::idFor($type, $anchorId));
        if ($anchor === null) {
            return $this->index->popular($type, $limit);
        }

        return array_map(
            static fn (SearchHit $hit): SearchDocument => $hit->document,
            $this->index->similarTo($anchor, $limit),
        );
    }

    /**
     * @return list<SearchDocument>
     */
    private function personalised(SearchType $type, ?string $userId, int $limit): array
    {
        if ($userId === null) {
            return $this->index->popular($type, $limit);
        }
        $recent = $this->analytics->recentForUser($userId, 1);
        $term = $recent[0] ?? null;
        if ($term === null || trim($term) === '') {
            return $this->index->popular($type, $limit);
        }

        $query = new SearchQuery(
            term: mb_strtolower($term),
            expandedTerms: [],
            type: $type,
            filters: new SearchFilters(),
            sort: SortOption::Relevance,
            page: 1,
            perPage: $limit,
        );
        $results = $this->index->search($query, $this->embedder->embed($term));

        $documents = array_map(static fn (SearchHit $hit): SearchDocument => $hit->document, $results->hits);

        return $documents !== [] ? $documents : $this->index->popular($type, $limit);
    }
}
