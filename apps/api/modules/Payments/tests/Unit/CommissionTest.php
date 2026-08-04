<?php

declare(strict_types=1);

use EruoFood\Payments\Infrastructure\Commission\ConfigCommissionCalculator;
use EruoFood\Shared\Domain\ValueObject\Money;

it('computes a basis-point commission plus a flat fee, capped at the gross', function (): void {
    $calc = new ConfigCommissionCalculator(rateBps: 1000, flatFeeMinor: 5000); // 10% + ₦50
    expect($calc->commissionOn(new Money(1000000, 'NGN'))->minorUnits)->toBe(105000);

    $tiny = new ConfigCommissionCalculator(rateBps: 1000, flatFeeMinor: 5000);
    expect($tiny->commissionOn(new Money(1000, 'NGN'))->minorUnits)->toBe(1000); // capped at gross
});

it('settles net = gross - commission - fees', function (): void {
    $calc = new ConfigCommissionCalculator(1500, 0); // 15%
    $gross = new Money(2000000, 'NGN');
    $commission = $calc->commissionOn($gross);
    $fees = $calc->feeOn($gross);
    $net = $gross->subtract($commission)->subtract($fees);
    expect($commission->minorUnits)->toBe(300000)
        ->and($net->minorUnits)->toBe(1700000);
});
