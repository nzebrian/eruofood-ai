<?php

declare(strict_types=1);

namespace EruoFood\Search\Application\Service;

use EruoFood\Search\Domain\Analytics\PopularTerm;
use EruoFood\Search\Domain\Analytics\SearchAnalyticsRepository;
use EruoFood\Search\Domain\Analytics\SearchMetrics;

/**
 * The search-analytics use cases: attributing result clicks (for click-through
 * rate and recommendation performance) and serving the admin dashboards —
 * headline metrics, popular searches and failed (zero-result) searches.
 */
final readonly class SearchAnalyticsService
{
    public function __construct(
        private SearchAnalyticsRepository $analytics,
    ) {
    }

    public function recordClick(string $queryId, string $documentId, int $position, bool $fromRecommendation = false): void
    {
        $this->analytics->recordClick($queryId, $documentId, max(0, $position), $fromRecommendation);
    }

    public function metrics(int $days): SearchMetrics
    {
        return $this->analytics->metrics($days);
    }

    /**
     * @return list<PopularTerm>
     */
    public function popular(int $days, int $limit): array
    {
        return $this->analytics->popular($days, $limit);
    }

    /**
     * @return list<PopularTerm>
     */
    public function failed(int $days, int $limit): array
    {
        return $this->analytics->failed($days, $limit);
    }
}
