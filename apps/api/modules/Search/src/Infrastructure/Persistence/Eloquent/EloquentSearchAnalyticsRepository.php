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

    /**
     * @param callable(\Illuminate\Database\Eloquent\Builder<SearchQueryLogModel>): void $constrain
     * @return list<PopularTerm>
     */
    private function groupedTerms(int $days, int $limit, callable $constrain): array
    {
        $query = SearchQueryLogModel::query()
            ->where('created_at', '>=', $this->threshold($days))
            ->where('term', '!=', '');
        $constrain($query);

        /** @var list<object{term: string, c: int}> $rows */
        $rows = $query->select('term', DB::raw('count(*) as c'))
            ->groupBy('term')
            ->orderByDesc('c')
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
