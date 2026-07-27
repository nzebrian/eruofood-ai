<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/** Raised when a wallet is credited (top-up, refund, settlement, transfer in). */
final readonly class WalletCredited implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(
        public string $walletId,
        public string $ownerType,
        public string $ownerId,
        public int $amountMinor,
        public int $balanceAfterMinor,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'payments.wallet_credited';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
