<?php

declare(strict_types=1);

namespace EruoFood\Analytics\Domain\ValueObject;

/** A breakdown dimension for a metric (e.g. provider=paystack). */
final readonly class Dimension
{
    public function __construct(
        public string $key,
        public string $value,
    ) {
    }
}
