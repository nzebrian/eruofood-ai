<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Settlement;

use DateTimeImmutable;
use EruoFood\Payments\Domain\Enum\AccrualType;
use EruoFood\Payments\Domain\Exception\PaymentsInvalidState;
use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * One line of "we owe this merchant this much, because of this".
 *
 * ## The record that makes a payable derivable
 *
 * Before this existed, the answer to "what do we owe merchant X" was a number
 * an operator typed into a request body. There was no query that could produce
 * it, because nothing recorded, per order, what the merchant had earned. An
 * accrual is that record: immutable, carrying the commission rate that was in
 * force at the time.
 *
 * A merchant's payable is `Σ accrual net − Σ settlement lines`. Nothing else is
 * authoritative, and nothing stores that total as a fact.
 *
 * ## Two shapes, one table
 *
 * An {@see AccrualType::Earning} is what a merchant made on an order: exactly
 * one per order, all amounts non-negative. An {@see AccrualType::RefundAdjustment}
 * is what a refund took back: one per refund, all amounts non-positive. Summing
 * the column gives the right answer without either row ever being edited.
 *
 * ## Append-only, and why the rate is copied
 *
 * A commission rate change six months from now must not alter what a merchant
 * was owed for an order last March, so the rate is snapshotted onto the row
 * rather than re-read at settlement time.
 *
 * ## `ledgerPosted` and the report-only cycle
 *
 * An accrual can exist without its ledger movement. During the report-only
 * cycle the platform writes accruals so their totals can be compared against
 * the figures finance produces by hand, posts nothing to the ledger, and moves
 * no money.
 *
 * Such an accrual is **not settleable**, and that is enforced rather than
 * documented: paying out against an accrual whose `Escrow → MerchantPayable`
 * posting never happened would take money the ledger still calls escrow, and
 * the books would stop balancing at the exact moment somebody was paid.
 */
final class PayableAccrual
{
    private function __construct(
        private readonly string $id,
        private readonly AccrualType $type,
        private readonly string $merchantType,
        private readonly string $merchantId,
        private readonly string $orderId,
        private readonly string $paymentId,
        private readonly ?string $refundId,
        private readonly Money $gross,
        private readonly Money $commission,
        private readonly Money $fee,
        private readonly Money $net,
        private readonly int $commissionRateBps,
        private readonly bool $ledgerPosted,
        private readonly string $correlationId,
        private readonly DateTimeImmutable $accruedAt,
    ) {
    }

    /**
     * Derive an earning from a captured payment's own figures.
     *
     * Every amount is an argument the caller reads out of the ledger, never out
     * of a request. The one thing this constructor will not do is accept a net
     * that does not follow from the other three.
     */
    public static function accrue(
        string $id,
        string $merchantType,
        string $merchantId,
        string $orderId,
        string $paymentId,
        Money $gross,
        Money $commission,
        Money $fee,
        int $commissionRateBps,
        bool $ledgerPosted,
        string $correlationId,
        DateTimeImmutable $now,
    ): self {
        foreach ([$gross, $commission, $fee] as $amount) {
            if ($amount->minorUnits < 0) {
                throw new PaymentsInvalidState('An earning accrual cannot carry a negative amount.');
            }
            if ($amount->currency !== $gross->currency) {
                // Cannot happen from the current caller, which takes all three
                // from one payment. Asserted anyway: a mixed-currency accrual
                // would produce a payable that sums unlike things, and the
                // failure would surface as a wrong payout rather than an error.
                throw new PaymentsInvalidState('An accrual cannot mix currencies.');
            }
        }

        $net = $gross->subtract($commission)->subtract($fee);
        if ($net->minorUnits < 0) {
            throw new PaymentsInvalidState('An accrual cannot have a negative net; commission and fees exceed the gross.');
        }

        return new self(
            $id,
            AccrualType::Earning,
            $merchantType,
            $merchantId,
            $orderId,
            $paymentId,
            null,
            $gross,
            $commission,
            $fee,
            $net,
            $commissionRateBps,
            $ledgerPosted,
            $correlationId,
            $now,
        );
    }

    /**
     * The compensating row a refund writes.
     *
     * $refundedGross is given as a positive amount — the caller should not have
     * to remember a sign convention — and is negated here, so there is exactly
     * one place in the codebase that decides which direction a refund moves a
     * payable.
     *
     * Commission is **not** clawed back. The platform keeps its commission on a
     * refunded order, which is a commercial decision rather than an accounting
     * necessity; it is recorded here rather than hidden in a service so that
     * changing it is a visible change. The consequence is that the merchant
     * bears the whole refund, so this is the conservative direction: the
     * platform under-pays rather than over-pays, and an under-payment is
     * correctable while an over-payment has to be asked for back.
     */
    public static function refundAdjustment(
        string $id,
        string $merchantType,
        string $merchantId,
        string $orderId,
        string $paymentId,
        string $refundId,
        Money $refundedGross,
        bool $ledgerPosted,
        string $correlationId,
        DateTimeImmutable $now,
    ): self {
        if ($refundedGross->minorUnits < 0) {
            throw new PaymentsInvalidState('A refund adjustment takes the refunded amount as a positive value.');
        }

        $zero = new Money(0, $refundedGross->currency);
        $negative = $zero->subtract($refundedGross);

        return new self(
            $id,
            AccrualType::RefundAdjustment,
            $merchantType,
            $merchantId,
            $orderId,
            $paymentId,
            $refundId,
            $negative,
            $zero,
            $zero,
            $negative,
            0,
            $ledgerPosted,
            $correlationId,
            $now,
        );
    }

    public static function reconstitute(
        string $id,
        AccrualType $type,
        string $merchantType,
        string $merchantId,
        string $orderId,
        string $paymentId,
        ?string $refundId,
        Money $gross,
        Money $commission,
        Money $fee,
        Money $net,
        int $commissionRateBps,
        bool $ledgerPosted,
        string $correlationId,
        DateTimeImmutable $accruedAt,
    ): self {
        return new self(
            $id,
            $type,
            $merchantType,
            $merchantId,
            $orderId,
            $paymentId,
            $refundId,
            $gross,
            $commission,
            $fee,
            $net,
            $commissionRateBps,
            $ledgerPosted,
            $correlationId,
            $accruedAt,
        );
    }

    /**
     * Whether this accrual may be included in a settlement run.
     *
     * False for a report-only accrual, and false for a refund adjustment — a
     * negative row reduces the payable by being summed, and is never itself a
     * line on a settlement run. The settlement query filters on the same
     * conditions, and a domain guard re-checks them when a line is created: two
     * places on purpose, because the query is an optimisation and the guard is
     * the rule.
     */
    public function isSettleable(): bool
    {
        return $this->ledgerPosted && $this->type === AccrualType::Earning;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function type(): AccrualType
    {
        return $this->type;
    }

    public function merchantType(): string
    {
        return $this->merchantType;
    }

    public function merchantId(): string
    {
        return $this->merchantId;
    }

    public function orderId(): string
    {
        return $this->orderId;
    }

    public function paymentId(): string
    {
        return $this->paymentId;
    }

    public function refundId(): ?string
    {
        return $this->refundId;
    }

    public function gross(): Money
    {
        return $this->gross;
    }

    public function commission(): Money
    {
        return $this->commission;
    }

    public function fee(): Money
    {
        return $this->fee;
    }

    public function net(): Money
    {
        return $this->net;
    }

    public function commissionRateBps(): int
    {
        return $this->commissionRateBps;
    }

    public function ledgerPosted(): bool
    {
        return $this->ledgerPosted;
    }

    public function correlationId(): string
    {
        return $this->correlationId;
    }

    public function accruedAt(): DateTimeImmutable
    {
        return $this->accruedAt;
    }
}
