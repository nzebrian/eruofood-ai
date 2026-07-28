<?php

declare(strict_types=1);

namespace EruoFood\Reviews\Infrastructure\Persistence\Eloquent;

use EruoFood\Reviews\Domain\Enum\ReviewStatus;
use EruoFood\Reviews\Domain\Enum\SubjectType;
use EruoFood\Reviews\Domain\Review\ReviewStatsRepository;
use EruoFood\Reviews\Infrastructure\Persistence\Eloquent\Model\ReviewModel;
use Illuminate\Support\Facades\DB;

/**
 * Aggregate reporting queries for the review analytics workspace. Everything is
 * counted in SQL (grouped counts) so the analytics never loads whole reviews;
 * the maths is identical on Postgres and sqlite (no DB-specific functions).
 */
final class EloquentReviewStatsRepository implements ReviewStatsRepository
{
    public function countsByStatus(): array
    {
        /** @var array<string, int> $rows */
        $rows = ReviewModel::query()->select('status', DB::raw('count(*) as c'))
            ->groupBy('status')->pluck('c', 'status')->map(fn ($v): int => (int) $v)->all();

        return $rows;
    }

    public function publishedDistribution(): array
    {
        /** @var array<int|string, int> $rows */
        $rows = ReviewModel::query()->where('status', ReviewStatus::Published->value)
            ->select('rating', DB::raw('count(*) as c'))
            ->groupBy('rating')->pluck('c', 'rating')->map(fn ($v): int => (int) $v)->all();

        $distribution = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        foreach ($rows as $stars => $count) {
            $distribution[(int) $stars] = (int) $count;
        }

        return $distribution;
    }

    public function publishedCount(): int
    {
        return (int) ReviewModel::query()->where('status', ReviewStatus::Published->value)->count();
    }

    public function verifiedCount(): int
    {
        return (int) ReviewModel::query()
            ->where('status', ReviewStatus::Published->value)
            ->where('verified_purchase', true)
            ->count();
    }

    public function countsBySubjectType(array $types): array
    {
        /** @var array<string, int> $rows */
        $rows = ReviewModel::query()->where('status', ReviewStatus::Published->value)
            ->select('subject_type', DB::raw('count(*) as c'))
            ->groupBy('subject_type')->pluck('c', 'subject_type')->map(fn ($v): int => (int) $v)->all();

        $out = [];
        foreach ($types as $type) {
            $out[$type->value] = $rows[$type->value] ?? 0;
        }

        return $out;
    }
}
