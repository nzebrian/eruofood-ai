<?php

declare(strict_types=1);

namespace EruoFood\Reviews\Domain\ValueObject;

use EruoFood\Reviews\Domain\Exception\ReviewsInvalidState;

/** A star rating, constrained to 1–5. */
final readonly class Rating
{
    public function __construct(public int $value)
    {
        if ($value < 1 || $value > 5) {
            throw new ReviewsInvalidState('A rating must be between 1 and 5.');
        }
    }
}
