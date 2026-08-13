<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Application\Service;

use DateTimeImmutable;
use EruoFood\Loyalty\Domain\Account\LoyaltyAccountRepository;
use EruoFood\Loyalty\Domain\Event\PointsRedeemed;
use EruoFood\Loyalty\Domain\Event\RewardRedeemed;
use EruoFood\Loyalty\Domain\Exception\LoyaltyInvalidState;
use EruoFood\Loyalty\Domain\Exception\LoyaltyNotAuthorized;
use EruoFood\Loyalty\Domain\Exception\LoyaltyNotFound;
use EruoFood\Loyalty\Domain\Reward\Redemption;
use EruoFood\Loyalty\Domain\Reward\RedemptionRepository;
use EruoFood\Loyalty\Domain\Reward\Reward;
use EruoFood\Loyalty\Domain\Reward\RewardRepository;
use EruoFood\Loyalty\Domain\ValueObject\Points;
use EruoFood\Shared\Domain\EventBus;
use EruoFood\Shared\Domain\Paginated;
use EruoFood\Shared\Domain\TransactionManager;

/**
 * Redeeming points for a reward. It debits the member's points, decrements the
 * reward's stock and issues a {@see Redemption} voucher atomically, then
 * publishes {@see RewardRedeemed} — the consuming context (Payments/Commerce)
 * reads the voucher to apply the benefit; Loyalty never applies a discount.
 * Cancelling a redemption refunds the points and restocks the reward.
 */
final readonly class RedemptionService
{
    public function __construct(
        private RewardRepository $rewards,
        private RedemptionRepository $redemptions,
        private LoyaltyAccountRepository $accounts,
        private LoyaltyService $loyalty,
        private EventBus $events,
        private TransactionManager $transactions,
    ) {
    }

    /**
     * Spend points on a reward.
     *
     * Points and stock are both finite, so the whole redemption runs in one
     * transaction with both rows locked. Without it, two requests can each read
     * a balance of 500 against a 500-point reward and both succeed, or two
     * members can claim the last unit in stock.
     */
    public function redeem(string $userId, string $rewardId): Redemption
    {
        [$redemption, $reward, $balanceAfter] = $this->transactions->atomic(function () use ($userId, $rewardId): array {
            $now = new DateTimeImmutable();

            $reward = $this->rewards->findByIdForUpdate($rewardId) ?? throw LoyaltyNotFound::of('reward', $rewardId);
            if (! $reward->isRedeemableAt($now)) {
                throw new LoyaltyInvalidState('This reward is not currently available.');
            }

            $account = $this->loyalty->lockedAccountFor($userId);
            // Debit points (throws LoyaltyInvalidState if the balance can't cover it).
            $account->redeem(new Points($reward->pointsCost()), 'redemption', $reward->id(), $this->accounts->nextEntryIdentity(), $now);
            $reward->consumeStock();

            $redemption = Redemption::issue(
                $this->redemptions->nextIdentity(),
                $reward->id(),
                $userId,
                $this->redemptions->nextCode(),
                $reward->pointsCost(),
                $reward->benefitType(),
                $reward->benefitValue(),
                $now,
            );

            $this->accounts->save($account);
            $this->rewards->save($reward);
            $this->redemptions->save($redemption);

            return [$redemption, $reward, $account->balance()];
        });

        $this->events->publish(new PointsRedeemed($userId, $reward->pointsCost(), $balanceAfter, $reward->id()));
        $this->events->publish(new RewardRedeemed(
            $redemption->id(),
            $userId,
            $reward->id(),
            $redemption->code(),
            $reward->benefitType(),
            $reward->benefitValue(),
        ));

        return $redemption;
    }

    public function fulfill(string $code): Redemption
    {
        $redemption = $this->redemptions->findByCode($code) ?? throw LoyaltyNotFound::of('redemption', $code);
        $redemption->fulfill(new DateTimeImmutable());
        $this->redemptions->save($redemption);

        return $redemption;
    }

    public function cancel(string $redemptionId, string $userId): Redemption
    {
        // Cancelling gives points back and returns a unit of stock — the mirror
        // image of redeem(), and atomic for the same reasons.
        return $this->transactions->atomic(function () use ($redemptionId, $userId): Redemption {
            $redemption = $this->redemptions->findById($redemptionId) ?? throw LoyaltyNotFound::of('redemption', $redemptionId);
            if (! $redemption->isOwnedBy($userId)) {
                throw new LoyaltyNotAuthorized('You may only cancel your own redemption.');
            }
            $now = new DateTimeImmutable();
            $redemption->cancel($now);

            // Refund the points and restock the reward.
            $account = $this->loyalty->lockedAccountFor($userId);
            $account->adjust($redemption->pointsSpent(), 'redemption_refund', $this->accounts->nextEntryIdentity(), $now);
            $this->accounts->save($account);

            $reward = $this->rewards->findByIdForUpdate($redemption->rewardId());
            if ($reward instanceof Reward) {
                $reward->restock();
                $this->rewards->save($reward);
            }

            $this->redemptions->save($redemption);

            return $redemption;
        });
    }

    /**
     * @return Paginated<Redemption>
     */
    public function forUser(string $userId, int $page, int $perPage): Paginated
    {
        return $this->redemptions->forUser($userId, $page, $perPage);
    }

    public function get(string $redemptionId, string $userId): Redemption
    {
        $redemption = $this->redemptions->findById($redemptionId) ?? throw LoyaltyNotFound::of('redemption', $redemptionId);
        if (! $redemption->isOwnedBy($userId)) {
            throw new LoyaltyNotAuthorized('You may only view your own redemption.');
        }

        return $redemption;
    }
}
