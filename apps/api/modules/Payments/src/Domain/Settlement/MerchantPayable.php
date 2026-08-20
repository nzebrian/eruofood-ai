<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Settlement;

use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * What a merchant is owed, right now, derived.
 *
 * ## Never stored as truth
 *
 * There is no `payable_minor` column anywhere in M27, and that absence is
 * deliberate. A stored balance is a second copy of a fact that already exists
 * in the accruals and the settlement lines, and two copies of a financial fact
 * drift — usually quietly, usually in the direction nobody notices until a
 * merchant complains.
 *
 * So this is a value object built from a query, held for the length of one
 * request, and thrown away. The read-model snapshot table the plan allowed for
 * was not built, because the query is fast enough and a snapshot would have
 * been exactly the second copy this avoids.
 *
 * ## It can be zero, and it must never be negative
 *
 * Refund adjustments reduce the payable. If refunds on a merchant's orders
 * exceed what they have earned and not yet been paid, the arithmetic goes
 * below zero — which means the platform has over-paid them and is owed money
 * back. That is a real situation, not an error, so {@see amount()} reports it
 * honestly and {@see isSettleable()} refuses to pay it out.
 */
final readonly class MerchantPayable
{
    private function __construct(
        public string $merchantType,
        public string $merchantId,
        public Money $amount,
        public int $accrualCount,
    ) {
    }

    public static function of(
        string $merchantType,
        string $merchantId,
        int $payableMinor,
        string $currency,
        int $accrualCount = 0,
    ): self {
        return new self($merchantType, $merchantId, new Money($payableMinor, $currency), $accrualCount);
    }

    public function amount(): Money
    {
        return $this->amount;
    }

    /** Whether there is anything here worth settling. */
    public function isSettleable(): bool
    {
        return $this->amount->minorUnits > 0;
    }

    /**
     * True when refunds have taken a merchant's payable below zero.
     *
     * Surfaced rather than clamped, because clamping would hide the fact that
     * the platform is owed money and the next settlement would quietly start
     * from zero instead of from the debt.
     */
    public function isOverdrawn(): bool
    {
        return $this->amount->minorUnits < 0;
    }
}
