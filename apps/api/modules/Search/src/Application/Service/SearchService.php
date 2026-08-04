<?php

declare(strict_types=1);

namespace EruoFood\Search\Application\Service;

use function assert;

use EruoFood\Search\Application\DTO\ExecutedSearch;
use EruoFood\Search\Application\Port\EmbeddingGenerator;
use EruoFood\Search\Application\Port\SearchCache;
use EruoFood\Search\Domain\Analytics\SearchAnalyticsRepository;
use EruoFood\Search\Domain\Document\SearchIndexRepository;
use EruoFood\Search\Domain\Document\SearchResults;
use EruoFood\Search\Domain\Exception\SearchNotAuthorized;
use EruoFood\Search\Domain\ValueObject\SearchQuery;

/**
 * The search pipeline — the single entry point every search flows through:
 *
 *   authorise → embed the query → read-through cache → index query
 *   (lexical prefilter + filter push-down + vector/geo re-rank) → record
 *   analytics → return ranked results.
 *
 * Admin-only scopes (user search) are gated here. Analytics are recorded outside
 * the cache so every execution — even a cache hit — is measured for CTR and
 * zero-result tracking.
 */
final readonly class SearchService
{
    public function __construct(
        private SearchIndexRepository $index,
        private EmbeddingGenerator $embedder,
        private SearchAnalyticsRepository $analytics,
        private SearchCache $cache,
        private int $cacheTtl,
    ) {
    }

    public function search(SearchQuery $query, bool $isAdmin = false, ?string $userId = null): ExecutedSearch
    {
        if ($query->type->isAdminOnly() && ! $isAdmin) {
            throw new SearchNotAuthorized('This search scope is restricted to administrators.');
        }

        $embedding = $query->hasTerm() ? $this->embedder->embed($query->term) : null;

        $results = $this->cacheTtl > 0
            ? $this->cache->remember(
                'search:'.$query->cacheKey(),
                $this->cacheTtl,
                fn (): SearchResults => $this->index->search($query, $embedding),
            )
            : $this->index->search($query, $embedding);

        assert($results instanceof SearchResults);

        $queryId = $this->analytics->recordQuery($query->term, $query->type, $results->total, $userId);

        return new ExecutedSearch($results, $queryId);
    }
}
