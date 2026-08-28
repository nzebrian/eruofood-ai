<?php

declare(strict_types=1);

namespace EruoFood\Search\Domain\Analytics;

use DateTimeImmutable;
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
     * Terms that may be shown to an anonymous caller (M39-SEC-001).
     *
     * The public analytics boundary. `popular()`, `failed()` and `trending()`
     * below are ADMINISTRATIVE reads: they aggregate every logged query
     * regardless of the scope it was run against, which is correct for an
     * operator and wrong for the public. Before M39 the public `/trending` and
     * `/suggestions` routes consumed `popular()`/`trending()` directly, so any
     * user's verbatim query string — including terms an administrator typed
     * against the admin-only `user` scope — was served to anonymous callers.
     *
     * This method applies the two constraints that make a term publishable:
     *
     *  - it was recorded against a scope the public may search
     *    ({@see \EruoFood\Search\Domain\Enum\SearchType::publicScopeValues()});
     *  - it occurs at least `$minOccurrences` times, so a phrase one person
     *    typed once cannot be broadcast.
     *
     * This is privacy SUPPRESSION, not anonymity: a term repeated often enough
     * by one determined user still qualifies. Raw query strings remain
     * sensitive data.
     *
     * @param int $minOccurrences minimum qualifying occurrences; below this a
     *                            term is withheld entirely
     * @return list<PopularTerm>
     */
    public function publicTerms(int $days, int $limit, int $minOccurrences): array;

    /**
     * Most-queried terms with matches, over the last N days.
     *
     * ADMINISTRATIVE: spans every scope, including admin-only ones, and applies
     * no occurrence threshold. Never serve this to an unauthenticated caller —
     * use {@see self::publicTerms()}.
     *
     * @return list<PopularTerm>
     */
    public function popular(int $days, int $limit): array;

    /**
     * Most-queried terms that returned nothing, over the last N days.
     *
     * ADMINISTRATIVE — see {@see self::popular()}. Zero-result terms are the
     * ones an operator most needs and the public least should see.
     *
     * @return list<PopularTerm>
     */
    public function failed(int $days, int $limit): array;

    /**
     * Trending terms (fastest-rising by recent volume) over the last N days.
     *
     * ADMINISTRATIVE — see {@see self::popular()}.
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

    /**
     * How many logged queries are older than `$before` (M40-SEC-001).
     *
     * Exists so a purge can be reported before it is performed. `Destroy` is
     * irreversible, so the dry run has to be able to say how much would go.
     */
    public function countQueriesBefore(DateTimeImmutable $before): int;

    /**
     * Delete logged queries older than `$before`, in bounded batches.
     *
     * Returns the number of rows removed. Implementations MUST delete strictly
     * `< $before` — a row exactly at the cutoff is inside the retention window
     * and is kept — and MUST NOT materialise the table in memory: this runs
     * against a log that grows with every search on the platform.
     *
     * @param int $chunkSize maximum rows per statement
     */
    public function purgeQueriesBefore(DateTimeImmutable $before, int $chunkSize): int;
}
