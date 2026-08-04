<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Ledger;

use DateTimeImmutable;
use EruoFood\Payments\Domain\Enum\LedgerAccount;
use EruoFood\Payments\Domain\Enum\TransactionDirection;
use EruoFood\Payments\Domain\Enum\TransactionType;
use EruoFood\Payments\Domain\Exception\PaymentsInvalidState;
use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * Builds a balanced group of {@see LedgerEntry} objects for one financial event.
 * Debits and credits must net to zero before the group can be posted, enforcing
 * the double-entry invariant in the domain rather than the database.
 */
final class LedgerPosting
{
    /** @var list<LedgerEntry> */
    private array $entries = [];

    public function __construct(
        private readonly string $correlationId,
        private readonly TransactionType $type,
        private readonly ?string $reference,
        private readonly DateTimeImmutable $at,
        private readonly IdentityGenerator $ids,
    ) {
    }

    public function debit(LedgerAccount $account, Money $amount): self
    {
        $this->entries[] = new LedgerEntry(
            $this->ids->next(),
            $this->correlationId,
            $account,
            TransactionDirection::Debit,
            $amount,
            $this->type,
            $this->reference,
            $this->at,
        );

        return $this;
    }

    public function credit(LedgerAccount $account, Money $amount): self
    {
        $this->entries[] = new LedgerEntry(
            $this->ids->next(),
            $this->correlationId,
            $account,
            TransactionDirection::Credit,
            $amount,
            $this->type,
            $this->reference,
            $this->at,
        );

        return $this;
    }

    /** @return list<LedgerEntry> */
    public function balanced(): array
    {
        $sum = 0;
        foreach ($this->entries as $entry) {
            $sum += $entry->signedMinor();
        }
        if ($sum !== 0) {
            throw new PaymentsInvalidState('Ledger posting does not balance.');
        }

        return $this->entries;
    }
}
