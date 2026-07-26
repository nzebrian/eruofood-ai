<?php

declare(strict_types=1);

namespace EruoFood\Ai\Infrastructure\RateLimit;

use EruoFood\Ai\Application\Port\AiRateLimiter;

/** No-op limiter used when AI rate limiting is disabled (e.g. in tests). */
final readonly class NullAiRateLimiter implements AiRateLimiter
{
    public function hit(string $userId): void
    {
    }
}
