<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Application\Service;

use EruoFood\Loyalty\Domain\Account\LoyaltyStatsRepository;
use EruoFood\Loyalty\Domain\Account\TierPolicy;

/**
 * Read-only analytics over the loyalty programme for the admin workspace:
 * membership, points liability (outstanding balance), the earned/redeemed/expired
 * flow, the tier distribution and the most-popular rewards. Aggregate reads only.
 */
final readonly class LoyaltyAnalyticsService
{
    public function __construct(
        private LoyaltyStatsRepository $stats,
        private TierPolicy $tiers,
    ) {
    }

    /**
     * @return array{
     *     members: int,
     *     points_outstanding: int,
     *     points_by_type: array<string, int>,
     *     members_by_tier: array<string, int>,
     *     top_rewards: list<array{reward_id: string, redemptions: int, points: int}>
     * }
     */
    public function overview(int $topRewards = 5): array
    {
        $byTier = $this->stats->membersByTier();
        // Ensure every configured tier appears, even with zero members.
        $tierCounts = [];
        foreach ($this->tiers->all() as $tier) {
            $tierCounts[$tier->key] = $byTier[$tier->key] ?? 0;
        }

        return [
            'members' => $this->stats->memberCount(),
            'points_outstanding' => $this->stats->pointsOutstanding(),
            'points_by_type' => $this->stats->pointsByType(),
            'members_by_tier' => $tierCounts,
            'top_rewards' => $this->stats->topRewards($topRewards),
        ];
    }
}
