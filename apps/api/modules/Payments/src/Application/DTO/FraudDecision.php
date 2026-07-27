<?php

declare(strict_types=1);

namespace EruoFood\Payments\Application\DTO;

/** The result of a fraud assessment hook. */
final readonly class FraudDecision
{
    public function __construct(
        public bool $allow,
        public int $score,
        public ?string $reason = null,
    ) {
    }

    public static function allow(): self
    {
        return new self(true, 0, null);
    }
}
