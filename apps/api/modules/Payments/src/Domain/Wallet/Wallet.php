<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Wallet;

use DateTimeImmutable;
use EruoFood\Payments\Domain\Enum\TransactionDirection;
use EruoFood\Payments\Domain\Enum\TransactionType;
use EruoFood\Payments\Domain\Enum\WalletOwnerType;
use EruoFood\Payments\Domain\Event\WalletCredited;
use EruoFood\Payments\Domain\Event\WalletLowBalance;
use EruoFood\Payments\Domain\Exception\PaymentsInvalidState;
use EruoFood\Shared\Domain\AggregateRoot;
use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * A money account for one owner (customer, vendor, restaurant, driver or the
 * platform). The aggregate root guards its own invariant — the balance never
 * goes negative — and appends an immutable {@see WalletTransaction} for every
 * movement (the statement). Credits and debits are the only way the balance
 * changes; a top-up, refund, settlement or transfer is expressed as one of
 * these with a {@see TransactionType}.
 *
 * New transactions accumulated in-memory are flushed by the persistence layer
 * via {@see releaseNewTransactions()}.
 */
final class Wallet extends AggregateRoot
{
    /** @var list<WalletTransaction> */
    private array $newTransactions = [];

    private function __construct(
        private readonly string $id,
        private readonly WalletOwnerType $ownerType,
        private readonly string $ownerId,
        private Money $balance,
        private readonly string $currency,
        private readonly int $lowBalanceThreshold,
        private readonly DateTimeImmutable $createdAt,
    ) {
    }

    public static function open(
        string $id,
        WalletOwnerType $ownerType,
        string $ownerId,
        string $currency,
        int $lowBalanceThreshold,
        DateTimeImmutable $now,
    ): self {
        return new self($id, $ownerType, $ownerId, new Money(0, $currency), $currency, $lowBalanceThreshold, $now);
    }

    public static function reconstitute(
        string $id,
        WalletOwnerType $ownerType,
        string $ownerId,
        Money $balance,
        string $currency,
        int $lowBalanceThreshold,
        DateTimeImmutable $createdAt,
    ): self {
        return new self($id, $ownerType, $ownerId, $balance, $currency, $lowBalanceThreshold, $createdAt);
    }

    public function credit(Money $amount, TransactionType $type, ?string $reference, ?string $description, string $txnId, DateTimeImmutable $at): void
    {
        $this->assertPositive($amount);
        $this->balance = $this->balance->add($amount);
        $this->append($txnId, $type, TransactionDirection::Credit, $amount, $reference, $description, $at);
        $this->recordThat(new WalletCredited(
            $this->id,
            $this->ownerType->value,
            $this->ownerId,
            $amount->minorUnits,
            $this->balance->minorUnits,
        ));
    }

    public function debit(Money $amount, TransactionType $type, ?string $reference, ?string $description, string $txnId, DateTimeImmutable $at): void
    {
        $this->assertPositive($amount);
        if ($amount->minorUnits > $this->balance->minorUnits) {
            throw new PaymentsInvalidState('Insufficient wallet balance.');
        }
        $this->balance = $this->balance->subtract($amount);
        $this->append($txnId, $type, TransactionDirection::Debit, $amount, $reference, $description, $at);
        if ($this->balance->minorUnits <= $this->lowBalanceThreshold) {
            $this->recordThat(new WalletLowBalance(
                $this->id,
                $this->ownerType->value,
                $this->ownerId,
                $this->balance->minorUnits,
            ));
        }
    }

    public function isOwnedBy(string $userId): bool
    {
        return $this->ownerId === $userId;
    }

    /** @return list<WalletTransaction> */
    public function releaseNewTransactions(): array
    {
        $txns = $this->newTransactions;
        $this->newTransactions = [];

        return $txns;
    }

    private function append(string $txnId, TransactionType $type, TransactionDirection $direction, Money $amount, ?string $reference, ?string $description, DateTimeImmutable $at): void
    {
        $this->newTransactions[] = new WalletTransaction(
            $txnId,
            $this->id,
            $type,
            $direction,
            $amount,
            $this->balance,
            $reference,
            $description,
            $at,
        );
    }

    private function assertPositive(Money $amount): void
    {
        if ($amount->currency !== $this->currency) {
            throw new PaymentsInvalidState('Wallet currency mismatch.');
        }
        if ($amount->minorUnits <= 0) {
            throw new PaymentsInvalidState('Wallet movement must be positive.');
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function ownerType(): WalletOwnerType
    {
        return $this->ownerType;
    }

    public function ownerId(): string
    {
        return $this->ownerId;
    }

    public function balance(): Money
    {
        return $this->balance;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
