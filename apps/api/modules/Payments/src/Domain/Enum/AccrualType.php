<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Enum;

/**
 * What an accrual row is saying.
 *
 * ## Why refunds are rows and not edits
 *
 * A merchant earns £40 on an order; the customer is refunded £10 a week later.
 * The payable must fall by £10. The tempting implementation edits the accrual,
 * and it is wrong for the same reason editing a ledger entry is wrong: the
 * history of what was owed, and why it changed, disappears — and with it any
 * way to answer "was this merchant underpaid, and when did we decide that?"
 *
 * So a refund writes a second row with a negative net. The payable is the sum,
 * the arithmetic still balances, and the record of both facts survives.
 */
enum AccrualType: string
{
    /**
     * A merchant earned this. Amounts are non-negative and exactly one exists
     * per order — the unique index says so.
     */
    case Earning = 'earning';

    /**
     * A refund reduced what a merchant is owed. Amounts are non-positive, and
     * one exists per refund.
     */
    case RefundAdjustment = 'refund_adjustment';

    /** Whether rows of this type carry non-negative amounts. */
    public function isPositive(): bool
    {
        return $this === self::Earning;
    }
}
