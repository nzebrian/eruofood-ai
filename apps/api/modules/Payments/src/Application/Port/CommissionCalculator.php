<?php

declare(strict_types=1);

namespace EruoFood\Payments\Application\Port;

use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * The **Commission Engine** — computes the platform's commission and processing
 * fee on a gross amount. A port so pricing can vary per vendor/category without
 * touching settlement.
 */
interface CommissionCalculator
{
    public function commissionOn(Money $gross): Money;

    public function feeOn(Money $gross): Money;
}
