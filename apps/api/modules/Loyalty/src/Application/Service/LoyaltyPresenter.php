<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Application\Service;

use EruoFood\Loyalty\Domain\Account\LedgerEntry;
use EruoFood\Loyalty\Domain\Account\LoyaltyAccount;
use EruoFood\Loyalty\Domain\Account\Tier;
use EruoFood\Loyalty\Domain\Account\TierPolicy;
use EruoFood\Loyalty\Domain\Referral\Referral;
use EruoFood\Loyalty\Domain\Referral\ReferralCode;
use EruoFood\Loyalty\Domain\Reward\Redemption;
use EruoFood\Loyalty\Domain\Reward\Reward;

/** Maps Loyalty domain objects to API-shaped arrays. */
final readonly class LoyaltyPresenter
{
    public function __construct(private TierPolicy $tiers)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function account(LoyaltyAccount $account): array
    {
        $tier = $this->tiers->byKey($account->tierKey());
        $next = $this->tiers->next($account->lifetimePoints());

        return [
            'user_id' => $account->userId(),
            'balance' => $account->balance(),
            'lifetime_points' => $account->lifetimePoints(),
            'tier' => $tier !== null ? $this->tier($tier) : ['key' => $account->tierKey()],
            'next_tier' => $next !== null ? [
                'key' => $next->key,
                'name' => $next->name,
                'points_to_go' => max(0, $next->threshold - $account->lifetimePoints()),
            ] : null,
            'updated_at' => $account->updatedAt()->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function entry(LedgerEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'type' => $entry->type->value,
            'points' => $entry->points,
            'reason' => $entry->reason,
            'reference' => $entry->reference,
            'balance_after' => $entry->balanceAfter,
            'created_at' => $entry->createdAt->format(DATE_ATOM),
            'expires_at' => $entry->expiresAt?->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function reward(Reward $reward): array
    {
        return [
            'id' => $reward->id(),
            'name' => $reward->name(),
            'description' => $reward->description(),
            'benefit_type' => $reward->benefitType(),
            'benefit_value' => $reward->benefitValue(),
            'points_cost' => $reward->pointsCost(),
            'stock' => $reward->stock(),
            'active' => $reward->isActive(),
            'starts_at' => $reward->startsAt()?->format(DATE_ATOM),
            'ends_at' => $reward->endsAt()?->format(DATE_ATOM),
            'created_at' => $reward->createdAt()->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function redemption(Redemption $redemption): array
    {
        return [
            'id' => $redemption->id(),
            'reward_id' => $redemption->rewardId(),
            'user_id' => $redemption->userId(),
            'code' => $redemption->code(),
            'points_spent' => $redemption->pointsSpent(),
            'benefit_type' => $redemption->benefitType(),
            'benefit_value' => $redemption->benefitValue(),
            'status' => $redemption->status()->value,
            'created_at' => $redemption->createdAt()->format(DATE_ATOM),
            'updated_at' => $redemption->updatedAt()->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function referral(Referral $referral): array
    {
        return [
            'id' => $referral->id(),
            'code' => $referral->code(),
            'referrer_user_id' => $referral->referrerUserId(),
            'referee_user_id' => $referral->refereeUserId(),
            'status' => $referral->status()->value,
            'created_at' => $referral->createdAt()->format(DATE_ATOM),
            'qualified_at' => $referral->qualifiedAt()?->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function referralCode(ReferralCode $code): array
    {
        return [
            'code' => $code->code,
            'user_id' => $code->userId,
            'created_at' => $code->createdAt->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function tier(Tier $tier): array
    {
        return $tier->toArray();
    }
}
