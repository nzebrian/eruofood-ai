<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Domain\ValueObject;

use EruoFood\Shared\Domain\Exception\InvalidArgumentException;

/** A 1–5 star rating. */
final readonly class Rating
{
    public function __construct(public int $value)
    {
        if ($value < 1 || $value > 5) {
            throw new InvalidArgumentException('Rating must be between 1 and 5.');
        }
    }
}
