<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Settlement;

use DateTimeImmutable;
use EruoFood\Payments\Domain\Exception\PaymentsInvalidState;
use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * "This run paid for that accrual."
 *
 * The smallest object in M27 and the one carrying its strongest guarantee: the
 * accrual id is unique across the whole table, so an accrual can be paid for
 * exactly once, ever.
 */
final readonly class SettlementLine
{
    private function __construct(
        public string $id,
        public string $settlementRunId,
        public string $accrualId,
        public Money $net,
        public DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * Build a line from an accrual, refusing anything that is not settleable.
     *
     * The check duplicates the one in the query that selected the accrual, and
     * that repetition is the point: the query is an optimisation and can be
     * rewritten, while this is the rule. A report-only accrual reaching a
     * settlement line would pay out money the ledger still calls escrow.
     */
    public static function forAccrual(
        string $id,
        string $settlementRunId,
        PayableAccrual $accrual,
        DateTimeImmutable $now,
    ): self {
        if (! $accrual->isSettleable()) {
            throw new PaymentsInvalidState(sprintf(
                'Accrual %s is not settleable: it is %s.',
                $accrual->id(),
                $accrual->ledgerPosted() ? 'not an earning' : 'report-only, with no ledger posting',
            ));
        }

        if ($accrual->net()->minorUnits <= 0) {
            throw new PaymentsInvalidState('A settlement line must carry a positive amount.');
        }

        return new self($id, $settlementRunId, $accrual->id(), $accrual->net(), $now);
    }

    public static function reconstitute(
        string $id,
        string $settlementRunId,
        string $accrualId,
        Money $net,
        DateTimeImmutable $createdAt,
    ): self {
        return new self($id, $settlementRunId, $accrualId, $net, $createdAt);
    }
}
