<?php

declare(strict_types=1);

namespace EruoFood\Search\Domain\Analytics;

/** Headline search KPIs over a window, for the admin search dashboard. */
final readonly class SearchMetrics
{
    public function __construct(
        public int $totalSearches,
        public int $uniqueTerms,
        public int $zeroResultSearches,
        public float $zeroResultRate,
        public int $clicks,
        public float $clickThroughRate,
        public float $avgResultsPerSearch,
        public int $recommendationClicks,
        public float $recommendationCtr,
    ) {
    }

    /** @return array<string, int|float> */
    public function toArray(): array
    {
        return [
            'total_searches' => $this->totalSearches,
            'unique_terms' => $this->uniqueTerms,
            'zero_result_searches' => $this->zeroResultSearches,
            'zero_result_rate' => round($this->zeroResultRate, 4),
            'clicks' => $this->clicks,
            'click_through_rate' => round($this->clickThroughRate, 4),
            'avg_results_per_search' => round($this->avgResultsPerSearch, 2),
            'recommendation_clicks' => $this->recommendationClicks,
            'recommendation_ctr' => round($this->recommendationCtr, 4),
        ];
    }
}
