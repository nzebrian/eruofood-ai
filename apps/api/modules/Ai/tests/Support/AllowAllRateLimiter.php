<?php

declare(strict_types=1);

namespace EruoFood\Ai\Tests\Support;

use EruoFood\Ai\Application\Port\AiRateLimiter;

/** A limiter that always allows and counts hits. */
final class AllowAllRateLimiter implements AiRateLimiter
{
    public int $hits = 0;

    public function hit(string $userId): void
    {
        $this->hits++;
    }
}
