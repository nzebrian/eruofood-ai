<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\ValueObject;

use EruoFood\Shared\Domain\Exception\InvalidArgumentException;

/**
 * Money value object — amount stored as integer minor units (e.g. kobo) plus
 * an ISO-4217 currency code. Never use floats for money (MASTER_PLAN.md §5.4).
 *
 * This is a generic, currency-agnostic primitive of the Shared Kernel; it is
 * not a business feature.
 */
final readonly class Money
{
    public function __construct(
        public int $minorUnits,
        public string $currency = 'NGN',
    ) {
        if (strlen($currency) !== 3) {
            throw new InvalidArgumentException('Currency must be a 3-letter ISO-4217 code.');
        }
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits + $other->minorUnits, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits - $other->minorUnits, $this->currency);
    }

    public function isZero(): bool
    {
        return $this->minorUnits === 0;
    }

    public function equals(self $other): bool
    {
        return $this->minorUnits === $other->minorUnits
            && $this->currency === $other->currency;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException('Cannot operate on different currencies.');
        }
    }
}
