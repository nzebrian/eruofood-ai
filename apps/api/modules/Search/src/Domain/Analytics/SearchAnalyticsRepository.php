<?php

declare(strict_types=1);

namespace EruoFood\Search\Domain\Analytics;

use EruoFood\Search\Domain\Enum\SearchType;

/**
 * The search-analytics log. Every executed query is recorded (with its result
 * count, so zero-result "failed" searches surface); clicks are attributed back
 * to their query so click-through and recommendation performance can be
 * computed. Also the source of popular/trending/recent term lists.
 */
interface SearchAnalyticsRepository
{
    /**
     * Record an executed query; returns the log id used to attribute clicks.
     */
    public function recordQuery(string $term, SearchType $type, int $resultCount, ?string $userId): string;

    public function recordClick(string $queryId, string $documentId, int $position, bool $fromRecommendation): void;

    /**
     * Most-queried terms with matches, over the last N days.
     *
     * @return list<PopularTerm>
     */
    public function popular(int $days, int $limit): array;

    /**
     * Most-queried terms that returned nothing, over the last N days.
     *
     * @return list<PopularTerm>
     */
    public function failed(int $days, int $limit): array;

    /**
     * Trending terms (fastest-rising by recent volume) over the last N days.
     *
     * @return list<string>
     */
    public function trending(int $days, int $limit): array;

    /**
     * A user's most recent distinct search terms.
     *
     * @return list<string>
     */
    public function recentForUser(string $userId, int $limit): array;

    public function metrics(int $days): SearchMetrics;
}
