<?php

declare(strict_types=1);

namespace EruoFood\Payments\Infrastructure\Commission;

use EruoFood\Payments\Application\Port\CommissionCalculator;
use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * The default Commission Engine: a basis-point commission plus an optional flat
 * fee on the gross. Rates come from config so ops can tune them without a
 * deploy; a per-vendor engine can replace this behind the same port.
 */
final readonly class ConfigCommissionCalculator implements CommissionCalculator
{
    public function __construct(
        private int $rateBps,
        private int $flatFeeMinor,
    ) {
    }

    public function commissionOn(Money $gross): Money
    {
        $amount = (int) round($gross->minorUnits * $this->rateBps / 10000) + $this->flatFeeMinor;

        return new Money(min($gross->minorUnits, max(0, $amount)), $gross->currency);
    }

    public function feeOn(Money $gross): Money
    {
        return new Money(0, $gross->currency); // processing fees passed through elsewhere
    }
}
