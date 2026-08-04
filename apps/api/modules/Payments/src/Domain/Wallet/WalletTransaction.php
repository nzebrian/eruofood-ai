<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Wallet;

use DateTimeImmutable;
use EruoFood\Payments\Domain\Enum\TransactionDirection;
use EruoFood\Payments\Domain\Enum\TransactionType;
use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * An immutable entry in a wallet's statement — a single credit or debit with the
 * running balance captured after it was applied. Together these form the
 * wallet's statement/history.
 */
final readonly class WalletTransaction
{
    public function __construct(
        public string $id,
        public string $walletId,
        public TransactionType $type,
        public TransactionDirection $direction,
        public Money $amount,
        public Money $balanceAfter,
        public ?string $reference,
        public ?string $description,
        public DateTimeImmutable $createdAt,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, string $currency): self
    {
        return new self(
            (string) $data['id'],
            (string) $data['wallet_id'],
            TransactionType::from((string) $data['type']),
            TransactionDirection::from((string) $data['direction']),
            new Money((int) $data['amount_minor'], $currency),
            new Money((int) $data['balance_after_minor'], $currency),
            isset($data['reference']) ? (string) $data['reference'] : null,
            isset($data['description']) ? (string) $data['description'] : null,
            new DateTimeImmutable((string) $data['created_at']),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'wallet_id' => $this->walletId,
            'type' => $this->type->value,
            'direction' => $this->direction->value,
            'amount_minor' => $this->amount->minorUnits,
            'balance_after_minor' => $this->balanceAfter->minorUnits,
            'reference' => $this->reference,
            'description' => $this->description,
            'created_at' => $this->createdAt->format(DATE_ATOM),
        ];
    }
}
