<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Domain\ValueObject;

use EruoFood\Catalog\Domain\Enum\MeasurementUnit;
use EruoFood\Shared\Domain\Exception\InvalidArgumentException;

/** A quantity: an amount paired with a unit (e.g. 2 cups). */
final readonly class Measurement
{
    public function __construct(
        public float $amount,
        public MeasurementUnit $unit,
    ) {
        if ($amount < 0) {
            throw new InvalidArgumentException('Measurement amount cannot be negative.');
        }
    }

    public function __toString(): string
    {
        return rtrim(rtrim(number_format($this->amount, 2), '0'), '.').' '.$this->unit->value;
    }
}
