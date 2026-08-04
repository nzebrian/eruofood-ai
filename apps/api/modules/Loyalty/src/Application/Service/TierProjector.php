<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Application\Service;

use DateTimeImmutable;
use EruoFood\Loyalty\Domain\Account\LoyaltyAccount;
use EruoFood\Loyalty\Domain\Account\LoyaltyAccountRepository;
use EruoFood\Loyalty\Domain\Account\TierPolicy;
use EruoFood\Loyalty\Domain\Event\TierChanged;
use EruoFood\Shared\Domain\EventBus;

/**
 * Resolves a member's tier from their lifetime points and, when it changes,
 * persists it and publishes {@see TierChanged}. This is the single writer of a
 * member's tier — other contexts consume the event, they never compute a tier.
 * Idempotent: projecting an unchanged tier is a no-op.
 */
final readonly class TierProjector
{
    public function __construct(
        private LoyaltyAccountRepository $accounts,
        private TierPolicy $tiers,
        private EventBus $events,
    ) {
    }

    public function project(LoyaltyAccount $account): void
    {
        $from = $account->tierKey();
        $resolved = $this->tiers->resolve($account->lifetimePoints());
        if (! $account->assignTier($resolved->key, new DateTimeImmutable())) {
            return;
        }
        $this->accounts->save($account);
        $this->events->publish(new TierChanged($account->userId(), $from, $resolved->key, $account->lifetimePoints()));
    }
}
