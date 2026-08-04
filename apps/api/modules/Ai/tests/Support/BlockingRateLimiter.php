<?php

declare(strict_types=1);

namespace EruoFood\Ai\Tests\Support;

use EruoFood\Ai\Application\Port\AiRateLimiter;
use EruoFood\Ai\Domain\Exception\RateLimitExceeded;

/** A limiter that always rejects — for exercising the quota path. */
final class BlockingRateLimiter implements AiRateLimiter
{
    public function hit(string $userId): void
    {
        throw RateLimitExceeded::retryAfter(60);
    }
}
