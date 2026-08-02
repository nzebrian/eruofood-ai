<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\ValueObject;

/** The outcome of a rate-limit check — drives the standard rate-limit headers. */
final readonly class RateLimitResult
{
    public function __construct(
        public bool $allowed,
        public int $limit,
        public int $remaining,
        public int $resetAtEpoch,
    ) {
    }
}
