<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Ledger;

use DateTimeImmutable;
use EruoFood\Payments\Domain\Enum\LedgerAccount;
use EruoFood\Payments\Domain\Enum\TransactionDirection;
use EruoFood\Payments\Domain\Enum\TransactionType;
use EruoFood\Shared\Domain\Exception\InvalidArgumentException;
use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * A single posting in the double-entry transaction ledger. Entries are grouped
 * by a correlation id so the debit and credit legs of one financial event stay
 * linked and can be proven to balance. Immutable — the ledger is append-only,
 * giving a tamper-evident audit trail and tax-ready reporting.
 */
final readonly class LedgerEntry
{
    public function __construct(
        public string $id,
        public string $correlationId,
        public LedgerAccount $account,
        public TransactionDirection $direction,
        public Money $amount,
        public TransactionType $type,
        public ?string $reference,
        public DateTimeImmutable $postedAt,
    ) {
        if ($amount->minorUnits <= 0) {
            throw new InvalidArgumentException('Ledger amount must be positive.');
        }
    }

    public function signedMinor(): int
    {
        return $this->direction === TransactionDirection::Debit
            ? -$this->amount->minorUnits
            : $this->amount->minorUnits;
    }
}
