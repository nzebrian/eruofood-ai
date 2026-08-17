<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Settlement;

use DateTimeImmutable;
use EruoFood\Shared\Domain\Paginated;

/**
 * Append-only persistence port for {@see PayableAccrual}.
 *
 * There is no `update()` and no `delete()`, and that is the interface doing
 * work rather than being sparse: an accrual is a financial fact, and the only
 * correct way to change what a merchant is owed is to add another row.
 */
interface PayableAccrualRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?PayableAccrual;

    /**
     * The earning accrual for an order, if one exists. Ignores refund rows.
     *
     * Used as a fast path before attempting an insert. It is **not** the
     * idempotency guarantee — two concurrent callers both find nothing, and the
     * partial unique index decides between them. See {@see insert()}.
     */
    public function findEarningForOrder(string $orderId): ?PayableAccrual;

    /**
     * Insert a new accrual.
     *
     * Throws {@see \EruoFood\Payments\Domain\Exception\PaymentsConflict} when the
     * row would duplicate an existing earning for the order, or an existing
     * adjustment for the refund. Translated from the unique-index violation
     * rather than pre-checked, so a race cannot slip between the check and the
     * write.
     */
    public function insert(PayableAccrual $accrual): void;

    /**
     * Accruals for a merchant, newest first.
     *
     * @return Paginated<PayableAccrual>
     */
    public function forMerchant(string $merchantType, string $merchantId, int $page, int $perPage): Paginated;

    /**
     * Every settleable earning for a merchant in a window that is not already
     * on a settlement line, oldest first.
     *
     * The exclusion is a `NOT EXISTS` against `payments_settlement_lines`
     * rather than a status column on the accrual, because a status column would
     * be a second place the truth lives and the two would eventually disagree.
     *
     * @return list<PayableAccrual>
     */
    public function unsettledEarnings(
        string $merchantType,
        string $merchantId,
        string $currency,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd,
    ): array;

    /**
     * The merchant's payable in minor units: the signed sum of every accrual
     * whose ledger movement was posted, less everything already settled.
     *
     * The single authoritative answer to "what do we owe this merchant". It is
     * computed, never stored — see {@see MerchantPayable}.
     */
    public function derivedPayableMinor(string $merchantType, string $merchantId, string $currency): int;

    /**
     * The signed sum of posted accrual net across every merchant, for the
     * ledger reconciler. Must equal the `MerchantPayable` ledger balance plus
     * everything settled out of it.
     */
    public function postedNetMinor(): int;

    /**
     * Settleable earnings whose payment is not a captured payment.
     *
     * A row that should not exist: it would pay a merchant for an order nobody
     * paid for. Returned as raw identifiers rather than aggregates because the
     * reconciler only reports them — reconstituting an accrual it must not act
     * on would invite acting on it.
     *
     * @return list<array{accrual_id: string, payment_id: string, net_minor: int}>
     */
    public function orphanEarnings(int $limit): array;

    /**
     * Totals across every accrual, for the report-only comparison.
     *
     * @return array{count: int, earnings: int, adjustments: int, gross_minor: int, commission_minor: int, fee_minor: int, net_minor: int, reporting_only: int}
     */
    public function totals(): array;

    /**
     * Merchants holding a non-zero payable, for the settlement queue.
     *
     * @return list<array{merchant_type: string, merchant_id: string, currency: string, payable_minor: int, accruals: int}>
     */
    public function merchantsWithPayable(int $limit): array;
}
