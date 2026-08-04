<?php

declare(strict_types=1);

namespace EruoFood\Ai\Infrastructure\RateLimit;

use EruoFood\Ai\Application\Port\AiRateLimiter;
use EruoFood\Ai\Domain\Exception\RateLimitExceeded;
use Illuminate\Cache\RateLimiter as LaravelLimiter;

/**
 * Per-user AI quota backed by Laravel's cache-based rate limiter (a fixed window
 * of {@see $maxAttempts} requests per {@see $windowSeconds}). Protects the
 * platform's provider spend from a single abusive account.
 */
final readonly class LaravelAiRateLimiter implements AiRateLimiter
{
    public function __construct(
        private LaravelLimiter $limiter,
        private int $maxAttempts,
        private int $windowSeconds,
    ) {
    }

    public function hit(string $userId): void
    {
        $key = 'ai:rl:'.$userId;

        if ($this->limiter->tooManyAttempts($key, $this->maxAttempts)) {
            throw RateLimitExceeded::retryAfter($this->limiter->availableIn($key));
        }

        $this->limiter->hit($key, $this->windowSeconds);
    }
}
