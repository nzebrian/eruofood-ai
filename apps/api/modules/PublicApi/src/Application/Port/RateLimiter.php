<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Application\Port;

use EruoFood\PublicApi\Domain\ValueObject\RateLimitResult;

/**
 * A fixed-window rate limiter (Redis-backed in production, array in tests).
 * `hit` atomically increments the counter for `$key` within `$windowSeconds` and
 * reports whether the request is within `$max`.
 */
interface RateLimiter
{
    public function hit(string $key, int $max, int $windowSeconds): RateLimitResult;
}
