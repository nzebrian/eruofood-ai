<?php

declare(strict_types=1);

namespace EruoFood\Search\Application\Service;

use EruoFood\Search\Domain\Analytics\SearchAnalyticsRepository;
use EruoFood\Search\Domain\Document\SearchIndexRepository;
use EruoFood\Search\Domain\Enum\SearchType;

/**
 * The type-ahead surface: prefix autocomplete over indexed titles/keywords,
 * plus trending (rising query volume), popular (all-time), recent (per user)
 * and search suggestions blended from index terms and past queries.
 */
final readonly class AutocompleteService
{
    public function __construct(
        private SearchIndexRepository $index,
        private SearchAnalyticsRepository $analytics,
        private int $suggestionLimit,
        private int $trendingDays,
        private int $recentLimit,
    ) {
    }

    /**
     * @return list<string>
     */
    public function autocomplete(string $prefix, ?SearchType $type, ?int $limit = null): array
    {
        $prefix = trim($prefix);
        if ($prefix === '') {
            return [];
        }

        return $this->index->suggest($prefix, $type, $limit ?? $this->suggestionLimit);
    }

    /**
     * Blended suggestions: index-driven completions first, topped up with
     * popular past queries that share the prefix.
     *
     * @return list<string>
     */
    public function suggestions(string $prefix, ?SearchType $type): array
    {
        $fromIndex = $this->autocomplete($prefix, $type);
        if (count($fromIndex) >= $this->suggestionLimit) {
            return $fromIndex;
        }

        $needle = mb_strtolower(trim($prefix));
        $fromHistory = [];
        foreach ($this->analytics->popular($this->trendingDays, 50) as $popular) {
            if ($needle === '' || str_contains(mb_strtolower($popular->term), $needle)) {
                $fromHistory[] = $popular->term;
            }
        }

        return array_values(array_slice(array_unique([...$fromIndex, ...$fromHistory]), 0, $this->suggestionLimit));
    }

    /**
     * @return list<string>
     */
    public function trending(): array
    {
        return $this->analytics->trending($this->trendingDays, $this->suggestionLimit);
    }

    /**
     * @return list<string>
     */
    public function recent(string $userId): array
    {
        return $this->analytics->recentForUser($userId, $this->recentLimit);
    }
}
