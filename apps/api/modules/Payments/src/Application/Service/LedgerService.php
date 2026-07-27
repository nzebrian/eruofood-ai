<?php

declare(strict_types=1);

namespace EruoFood\Payments\Application\Service;

use DateTimeImmutable;
use EruoFood\Payments\Domain\Enum\LedgerAccount;
use EruoFood\Payments\Domain\Enum\TransactionType;
use EruoFood\Payments\Domain\Ledger\IdentityGenerator;
use EruoFood\Payments\Domain\Ledger\LedgerPosting;
use EruoFood\Payments\Domain\Ledger\LedgerRepository;
use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * Posts balanced groups to the double-entry ledger. Keeps posting logic in one
 * place so every financial event (capture, refund, settlement, wallet move)
 * writes a provably-balanced set of entries.
 */
final readonly class LedgerService
{
    public function __construct(
        private LedgerRepository $ledger,
        private IdentityGenerator $ids,
    ) {
    }

    public function newPosting(string $correlationId, TransactionType $type, ?string $reference): LedgerPosting
    {
        return new LedgerPosting($correlationId, $type, $reference, new DateTimeImmutable(), $this->ids);
    }

    public function commit(LedgerPosting $posting): void
    {
        $this->ledger->post($posting->balanced());
    }

    /**
     * Record a captured payment: money in (Cash) split into escrow (net to the
     * vendor), commission and processing fees.
     */
    public function recordCapture(string $correlationId, string $reference, Money $gross, Money $commission, Money $fees, Money $escrowNet): void
    {
        $posting = $this->newPosting($correlationId, TransactionType::Payment, $reference)
            ->debit(LedgerAccount::Cash, $gross)
            ->credit(LedgerAccount::Escrow, $escrowNet);
        if ($commission->minorUnits > 0) {
            $posting->credit(LedgerAccount::Commission, $commission);
        }
        if ($fees->minorUnits > 0) {
            $posting->credit(LedgerAccount::Fees, $fees);
        }
        $this->commit($posting);
    }

    /** Record a refund: money leaves escrow/cash back to the customer. */
    public function recordRefund(string $correlationId, string $reference, Money $amount): void
    {
        $this->commit(
            $this->newPosting($correlationId, TransactionType::Refund, $reference)
                ->debit(LedgerAccount::Refunds, $amount)
                ->credit(LedgerAccount::Cash, $amount),
        );
    }

    /** Record a settlement payout: escrow released to payouts. */
    public function recordSettlement(string $correlationId, string $reference, Money $net): void
    {
        $this->commit(
            $this->newPosting($correlationId, TransactionType::Settlement, $reference)
                ->debit(LedgerAccount::Escrow, $net)
                ->credit(LedgerAccount::Payouts, $net),
        );
    }

    public function balanceOf(LedgerAccount $account): int
    {
        return $this->ledger->balanceOf($account);
    }
}
