<?php

declare(strict_types=1);

use EruoFood\Shared\Domain\Exception\InvalidArgumentException;
use EruoFood\Shared\Domain\ValueObject\Money;

it('adds amounts of the same currency', function (): void {
    $total = (new Money(1500))->add(new Money(500));

    expect($total->minorUnits)->toBe(2000)
        ->and($total->currency)->toBe('NGN');
});

it('rejects operations across different currencies', function (): void {
    (new Money(1000, 'NGN'))->add(new Money(1000, 'USD'));
})->throws(InvalidArgumentException::class);

it('rejects invalid currency codes', function (): void {
    new Money(1000, 'NAIRA');
})->throws(InvalidArgumentException::class);
