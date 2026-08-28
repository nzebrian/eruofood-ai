<?php

declare(strict_types=1);

namespace EruoFood\Search\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Search\Domain\Analytics\PopularTerm;
use EruoFood\Search\Domain\Analytics\SearchAnalyticsRepository;
use EruoFood\Search\Domain\Analytics\SearchMetrics;
use EruoFood\Search\Domain\Enum\SearchType;
use EruoFood\Search\Infrastructure\Persistence\Eloquent\Model\SearchClickModel;
use EruoFood\Search\Infrastructure\Persistence\Eloquent\Model\SearchQueryLogModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EloquentSearchAnalyticsRepository implements SearchAnalyticsRepository
{
    public function recordQuery(string $term, SearchType $type, int $resultCount, ?string $userId): string
    {
        $id = (string) Str::orderedUuid();
        $model = new SearchQueryLogModel();
        $model->id = $id;
        $model->term = mb_strtolower(trim($term));
        $model->type = $type->value;
        $model->result_count = $resultCount;
        $model->user_id = $userId;
        $model->created_at = new DateTimeImmutable();
        $model->save();

        return $id;
    }

    public function recordClick(string $queryId, string $documentId, int $position, bool $fromRecommendation): void
    {
        $model = new SearchClickModel();
        $model->id = (string) Str::orderedUuid();
        $model->query_id = $queryId;
        $model->document_id = $documentId;
        $model->position = $position;
        $model->from_recommendation = $fromRecommendation;
        $model->created_at = new DateTimeImmutable();
        $model->save();
    }

    /**
     * The public analytics boundary (M39-SEC-001).
     *
     * Two constraints, both enforced HERE in the query rather than by a caller
     * remembering to filter:
     *
     *  1. `type` must be a scope the public may search. The column records the
     *     scope named on the request, so this is `SearchType::publicScopeValues()`
     *     — the concrete public document types AND `global`, which is what a
     *     default public search records.
     *  2. The term must occur at least `$minOccurrences` times, applied as a
     *     SQL `HAVING` so a suppressed term never leaves the database.
     *
     * `result_count > 0` matches `popular()`: a term that found nothing is a
     * zero-result signal for operators, not a suggestion for the public.
     */
    public function publicTerms(int $days, int $limit, int $minOccurrences): array
    {
        $threshold = max(1, $minOccurrences);

        return $this->groupedTerms(
            $days,
            $limit,
            static function ($q): void {
                $q->where('result_count', '>', 0)
                    ->whereIn('type', SearchType::publicScopeValues());
            },
            $threshold,
        );
    }

    public function popular(int $days, int $limit): array
    {
        return $this->groupedTerms($days, $limit, static function ($q): void {
            $q->where('result_count', '>', 0);
        });
    }

    public function failed(int $days, int $limit): array
    {
        return $this->groupedTerms($days, $limit, static function ($q): void {
            $q->where('result_count', '=', 0);
        });
    }

    public function trending(int $days, int $limit): array
    {
        return array_map(
            static fn (PopularTerm $t): string => $t->term,
            $this->groupedTerms($days, $limit, static function (): void {
            }),
        );
    }

    public function recentForUser(string $userId, int $limit): array
    {
        /** @var list<string> $terms */
        $terms = SearchQueryLogModel::query()
            ->where('user_id', $userId)
            ->where('term', '!=', '')
            ->orderByDesc('created_at')
            ->limit($limit * 4)
            ->pluck('term')
            ->all();

        $seen = [];
        foreach ($terms as $term) {
            $seen[$term] = true;
            if (count($seen) >= $limit) {
                break;
            }
        }

        return array_slice(array_keys($seen), 0, $limit);
    }

    public function metrics(int $days): SearchMetrics
    {
        $since = $this->threshold($days);
        $base = SearchQueryLogModel::query()->where('created_at', '>=', $since);

        $totalSearches = (int) (clone $base)->count();
        $uniqueTerms = (int) (clone $base)->where('term', '!=', '')->distinct()->count('term');
        $zeroResult = (int) (clone $base)->where('result_count', '=', 0)->count();
        $sumResults = (int) (clone $base)->sum('result_count');

        $clicks = (int) SearchClickModel::query()->where('created_at', '>=', $since)->count();
        $recClicks = (int) SearchClickModel::query()->where('created_at', '>=', $since)->where('from_recommendation', true)->count();

        return new SearchMetrics(
            totalSearches: $totalSearches,
            uniqueTerms: $uniqueTerms,
            zeroResultSearches: $zeroResult,
            zeroResultRate: $totalSearches > 0 ? $zeroResult / $totalSearches : 0.0,
            clicks: $clicks,
            clickThroughRate: $totalSearches > 0 ? $clicks / $totalSearches : 0.0,
            avgResultsPerSearch: $totalSearches > 0 ? $sumResults / $totalSearches : 0.0,
            recommendationClicks: $recClicks,
            recommendationCtr: $totalSearches > 0 ? $recClicks / $totalSearches : 0.0,
        );
    }

    public function countQueriesBefore(DateTimeImmutable $before): int
    {
        return (int) SearchQueryLogModel::query()
            ->where('created_at', '<', $before)
            ->count();
    }

    /**
     * Delete expired query-log rows in bounded batches (M40-SEC-001).
     *
     * ## Why ids, and not `DELETE … LIMIT`
     *
     * MySQL accepts `LIMIT` on `DELETE`; PostgreSQL does not, and PostgreSQL is
     * what this platform deploys on. So each batch selects at most
     * `$chunkSize` primary keys and deletes exactly those — portable across
     * both engines and across SQLite, which is what the test suite runs.
     *
     * ## Why batches at all
     *
     * The first purge on an installation that has been logging since M38 could
     * face a very large backlog. One statement over all of it would hold locks
     * for the duration and bloat WAL. A sequence of small deletes is
     * interruptible: killing it mid-run leaves the rows it has already removed
     * removed, and the rest still there for the next run.
     *
     * The loop is bounded by data, not by a fixed iteration count: it stops as
     * soon as a batch comes back empty, so a purge with nothing to do performs
     * exactly one cheap indexed SELECT.
     *
     * Strictly `<`: a row whose timestamp equals the cutoff is inside the
     * retention window and survives.
     */
    public function purgeQueriesBefore(DateTimeImmutable $before, int $chunkSize): int
    {
        $chunk = max(1, $chunkSize);
        $removed = 0;

        do {
            /** @var list<string> $ids */
            $ids = SearchQueryLogModel::query()
                ->where('created_at', '<', $before)
                ->orderBy('created_at')
                ->limit($chunk)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                break;
            }

            $removed += (int) SearchQueryLogModel::query()->whereIn('id', $ids)->delete();
        } while (count($ids) === $chunk);

        return $removed;
    }

    /**
     * Group the query log by term.
     *
     * Terms are already normalised at write time — `recordQuery()` stores
     * `mb_strtolower(trim($term))` — so grouping is on the normalised value and
     * this method deliberately does not normalise again. M39 did not change
     * that behaviour: altering the grouping key would silently change every
     * analytics figure the dashboards have reported so far, which is not a
     * security fix.
     *
     * `$minOccurrences` is applied as a SQL `HAVING`, so a suppressed term is
     * never read out of the database at all. It defaults to 1 — no suppression —
     * which is the administrative behaviour; only {@see self::publicTerms()}
     * raises it.
     *
     * @param callable(\Illuminate\Database\Eloquent\Builder<SearchQueryLogModel>): void $constrain
     * @return list<PopularTerm>
     */
    private function groupedTerms(int $days, int $limit, callable $constrain, int $minOccurrences = 1): array
    {
        $query = SearchQueryLogModel::query()
            ->where('created_at', '>=', $this->threshold($days))
            ->where('term', '!=', '');
        $constrain($query);

        $query->select('term', DB::raw('count(*) as c'))->groupBy('term');

        if ($minOccurrences > 1) {
            $query->havingRaw('count(*) >= ?', [$minOccurrences]);
        }

        /** @var list<object{term: string, c: int}> $rows */
        $rows = $query->orderByDesc('c')
            ->limit($limit)
            ->get()
            ->all();

        return array_map(
            static fn (object $row): PopularTerm => new PopularTerm((string) $row->term, (int) $row->c),
            $rows,
        );
    }

    private function threshold(int $days): string
    {
        return (new DateTimeImmutable('-'.max(1, $days).' days'))->format('Y-m-d H:i:s');
    }
}
