<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Domain\ValueObject;

use EruoFood\Loyalty\Domain\Exception\LoyaltyInvalidState;

/** A strictly-positive quantity of points — the magnitude of an earn or a spend. */
final readonly class Points
{
    public function __construct(public int $value)
    {
        if ($value <= 0) {
            throw new LoyaltyInvalidState('Points amount must be a positive whole number.');
        }
    }

    public static function of(int $value): self
    {
        return new self($value);
    }
}
