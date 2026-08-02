<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Infrastructure\Persistence\Eloquent;

use EruoFood\Loyalty\Domain\Account\LoyaltyStatsRepository;
use EruoFood\Loyalty\Domain\Enum\RedemptionStatus;
use EruoFood\Loyalty\Infrastructure\Persistence\Eloquent\Model\AccountModel;
use EruoFood\Loyalty\Infrastructure\Persistence\Eloquent\Model\LedgerEntryModel;
use EruoFood\Loyalty\Infrastructure\Persistence\Eloquent\Model\RedemptionModel;
use Illuminate\Support\Facades\DB;

/**
 * Aggregate reporting queries for the loyalty analytics workspace. Everything is
 * counted/summed in SQL so the analytics never loads whole accounts or ledgers;
 * the maths is identical on Postgres and sqlite (no DB-specific functions).
 */
final class EloquentLoyaltyStatsRepository implements LoyaltyStatsRepository
{
    public function memberCount(): int
    {
        return (int) AccountModel::query()->count();
    }

    public function pointsOutstanding(): int
    {
        return (int) AccountModel::query()->sum('balance');
    }

    public function pointsByType(): array
    {
        /** @var array<string, int> $rows */
        $rows = LedgerEntryModel::query()->select('type', DB::raw('sum(points) as p'))
            ->groupBy('type')->pluck('p', 'type')->map(fn ($v): int => (int) $v)->all();

        return $rows;
    }

    public function membersByTier(): array
    {
        /** @var array<string, int> $rows */
        $rows = AccountModel::query()->select('tier_key', DB::raw('count(*) as c'))
            ->groupBy('tier_key')->pluck('c', 'tier_key')->map(fn ($v): int => (int) $v)->all();

        return $rows;
    }

    public function topRewards(int $limit): array
    {
        $rows = RedemptionModel::query()
            ->where('status', '!=', RedemptionStatus::Cancelled->value)
            ->select('reward_id', DB::raw('count(*) as redemptions'), DB::raw('sum(points_spent) as points'))
            ->groupBy('reward_id')
            ->orderByDesc('redemptions')
            ->limit($limit)
            ->get();

        return array_map(
            static fn (RedemptionModel $r): array => [
                'reward_id' => (string) $r->reward_id,
                'redemptions' => (int) $r->getAttribute('redemptions'),
                'points' => (int) $r->getAttribute('points'),
            ],
            $rows->all(),
        );
    }
}
