<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Domain\Account;

/**
 * Aggregate read model for loyalty analytics — cheap grouped counts and sums so
 * the analytics workspace never loads whole accounts or ledgers.
 */
interface LoyaltyStatsRepository
{
    /** Total number of loyalty accounts. */
    public function memberCount(): int;

    /** Sum of all current balances (points outstanding as a liability). */
    public function pointsOutstanding(): int;

    /**
     * Signed points totalled by ledger entry type — earned, redeemed, expired,
     * adjusted.
     *
     * @return array<string, int> entry type value => summed points
     */
    public function pointsByType(): array;

    /**
     * Member counts per tier.
     *
     * @return array<string, int> tier key => member count
     */
    public function membersByTier(): array;

    /**
     * The most-redeemed rewards.
     *
     * @param int $limit
     *
     * @return list<array{reward_id: string, redemptions: int, points: int}>
     */
    public function topRewards(int $limit): array;
}
