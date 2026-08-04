<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Application\Service;

use DateTimeImmutable;
use EruoFood\Loyalty\Domain\Account\LedgerEntry;
use EruoFood\Loyalty\Domain\Account\LedgerQuery;
use EruoFood\Loyalty\Domain\Account\LoyaltyAccount;
use EruoFood\Loyalty\Domain\Account\LoyaltyAccountRepository;
use EruoFood\Loyalty\Domain\Account\TierPolicy;
use EruoFood\Loyalty\Domain\Event\PointsEarned;
use EruoFood\Loyalty\Domain\Event\PointsExpired;
use EruoFood\Loyalty\Domain\ValueObject\Points;
use EruoFood\Shared\Domain\EventBus;
use EruoFood\Shared\Domain\Paginated;

/**
 * The one entry point for a member's points balance — no business module keeps
 * its own points. It creates accounts on demand, awards points (applying the
 * member's tier multiplier), records admin adjustments, serves the ledger, and
 * runs the expiry sweep. Every award re-projects the tier through the projector,
 * the single writer of tier, so a tier can never drift from lifetime points.
 */
final readonly class LoyaltyService
{
    public function __construct(
        private LoyaltyAccountRepository $accounts,
        private TierPolicy $tiers,
        private TierProjector $tierProjector,
        private EventBus $events,
        private int $expiryDays,
    ) {
    }

    public function accountFor(string $userId): LoyaltyAccount
    {
        $account = $this->accounts->findByUser($userId);
        if ($account !== null) {
            return $account;
        }
        $account = LoyaltyAccount::open(
            $this->accounts->nextIdentity(),
            $userId,
            $this->tiers->all()[0]->key,
            new DateTimeImmutable(),
        );
        $this->accounts->save($account);

        return $account;
    }

    /**
     * Award `$basePoints` to a member (before the tier multiplier). Returns the
     * updated account, or null when the award rounds to nothing (no-op).
     */
    public function earn(string $userId, int $basePoints, string $reason, ?string $reference): ?LoyaltyAccount
    {
        if ($basePoints <= 0) {
            return null;
        }
        $account = $this->accountFor($userId);
        $tier = $this->tiers->byKey($account->tierKey()) ?? $this->tiers->resolve($account->lifetimePoints());
        $effective = (int) floor($basePoints * $tier->earnMultiplier);
        if ($effective <= 0) {
            return null;
        }

        $now = new DateTimeImmutable();
        $expiresAt = $this->expiryDays > 0 ? $now->modify(sprintf('+%d days', $this->expiryDays)) : null;
        $account->earn(new Points($effective), $reason, $reference, $this->accounts->nextEntryIdentity(), $expiresAt, $now);
        $this->accounts->save($account);

        $this->events->publish(new PointsEarned($userId, $effective, $account->balance(), $reason));
        $this->tierProjector->project($account);

        return $account;
    }

    /** A manual admin correction (positive or negative). */
    public function adjust(string $userId, int $delta, string $reason): LoyaltyAccount
    {
        $account = $this->accountFor($userId);
        $account->adjust($delta, $reason, $this->accounts->nextEntryIdentity(), new DateTimeImmutable());
        $this->accounts->save($account);
        $this->tierProjector->project($account);

        return $account;
    }

    /**
     * @return Paginated<LedgerEntry>
     */
    public function ledger(string $userId, int $page, int $perPage): Paginated
    {
        $account = $this->accountFor($userId);

        return $this->accounts->ledger(new LedgerQuery($account->id(), null, $page, $perPage));
    }

    /**
     * Sweep expired points. For each earn entry past its expiry, expire the
     * still-live remainder (original minus what already expired against it).
     *
     * @return int the number of accounts touched
     */
    public function runExpiry(int $limit): int
    {
        $now = new DateTimeImmutable();
        $touched = 0;
        foreach ($this->accounts->expirableEntries($now, $limit) as $entry) {
            $remaining = $entry->points - $this->accounts->expiredAgainst($entry->id);
            if ($remaining <= 0) {
                continue;
            }
            $account = $this->accounts->findById($entry->accountId);
            if ($account === null || $account->balance() <= 0) {
                continue;
            }
            $toExpire = min($remaining, $account->balance());
            $account->expire($toExpire, $entry->id, $this->accounts->nextEntryIdentity(), $now);
            $this->accounts->save($account);
            $this->events->publish(new PointsExpired($account->userId(), $toExpire, $account->balance()));
            $touched++;
        }

        return $touched;
    }
}
