<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/** Raised when a wallet's balance falls below the configured low-balance threshold. */
final readonly class WalletLowBalance implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(
        public string $walletId,
        public string $ownerType,
        public string $ownerId,
        public int $balanceMinor,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'payments.wallet_low_balance';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
