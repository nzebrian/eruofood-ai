<?php

declare(strict_types=1);

namespace EruoFood\Search\Application\Service;

use EruoFood\Search\Domain\Access\SearchScopeGate;
use EruoFood\Search\Domain\Analytics\PopularTerm;
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
        private SearchScopeGate $gate = new SearchScopeGate(),
        private int $publicTermMinOccurrences = 3,
    ) {
    }

    /**
     * @return list<string>
     */
    public function autocomplete(string $prefix, ?SearchType $type, ?int $limit = null, bool $isAdmin = false): array
    {
        // M38-SEC-001. This route is PUBLIC and accepts `?type=user`. Before
        // M38 nothing here checked the scope at all, so an anonymous caller
        // could have enumerated indexed user titles by prefix; the only thing
        // preventing it was that no provider indexes that type yet.
        //
        // Authorised BEFORE the prefix is even trimmed, so a refusal cannot
        // depend on the shape of the input. The RESOLVED scope is what goes to
        // the index — the first fix passed the caller's raw `$type` on and
        // discarded this return value, which meant a null/Global request was
        // authorised as "public" and then executed as "unfiltered".
        $scope = $this->gate->authorize($type, $isAdmin);

        $prefix = trim($prefix);
        if ($prefix === '') {
            return [];
        }

        return $this->index->suggest($prefix, $scope, $limit ?? $this->suggestionLimit);
    }

    /**
     * Blended suggestions: index-driven completions first, topped up with
     * popular past queries that share the prefix.
     *
     * @return list<string>
     */
    public function suggestions(string $prefix, ?SearchType $type, bool $isAdmin = false): array
    {
        $scope = $this->gate->authorize($type, $isAdmin);

        $fromIndex = $this->autocomplete($prefix, $scope, isAdmin: $isAdmin);
        if (count($fromIndex) >= $this->suggestionLimit) {
            return $fromIndex;
        }

        // M39-SEC-001. This used to read `analytics->popular()`, which spans
        // EVERY logged scope and applies no occurrence threshold — so a term an
        // administrator typed against the admin-only `user` scope, or a phrase
        // one person searched once, was blended into an anonymous caller's
        // suggestions. `publicTerms()` enforces both constraints in SQL.
        $needle = mb_strtolower(trim($prefix));
        $fromHistory = [];
        foreach ($this->publicTerms(50) as $popular) {
            if ($needle === '' || str_contains(mb_strtolower($popular->term), $needle)) {
                $fromHistory[] = $popular->term;
            }
        }

        return array_slice(array_unique([...$fromIndex, ...$fromHistory]), 0, $this->suggestionLimit);
    }

    /**
     * @return list<string>
     */
    public function trending(): array
    {
        // M39-SEC-001. Previously `analytics->trending()`, which is the
        // administrative view: every scope, no threshold, served unauthenticated.
        return array_map(
            static fn (PopularTerm $t): string => $t->term,
            $this->publicTerms($this->suggestionLimit),
        );
    }

    /**
     * The only analytics source either public route is allowed to read.
     *
     * Both public consumers funnel through here so there is one place to check
     * — and so a future endpoint cannot reach `popular()`/`trending()` by
     * copying the shape of an existing call.
     *
     * @return list<PopularTerm>
     */
    private function publicTerms(int $limit): array
    {
        return $this->analytics->publicTerms(
            $this->trendingDays,
            $limit,
            $this->publicTermMinOccurrences,
        );
    }

    /**
     * @return list<string>
     */
    public function recent(string $userId): array
    {
        return $this->analytics->recentForUser($userId, $this->recentLimit);
    }
}
