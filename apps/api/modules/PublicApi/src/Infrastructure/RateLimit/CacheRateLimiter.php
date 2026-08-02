<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Infrastructure\RateLimit;

use EruoFood\PublicApi\Application\Port\RateLimiter;
use EruoFood\PublicApi\Domain\ValueObject\RateLimitResult;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Fixed-window rate limiter over a cache store (Redis in production, array in
 * tests). The counter key is bucketed by window so it resets automatically; the
 * increment is atomic on stores that support it.
 */
final readonly class CacheRateLimiter implements RateLimiter
{
    public function __construct(private Cache $cache)
    {
    }

    public function hit(string $key, int $max, int $windowSeconds): RateLimitResult
    {
        $window = max(1, $windowSeconds);
        $bucket = (int) floor(time() / $window);
        $cacheKey = sprintf('%s:%d', $key, $bucket);

        $this->cache->add($cacheKey, 0, $window);
        $count = (int) $this->cache->increment($cacheKey);
        // Defensive: some drivers return false/0 if the key just expired.
        if ($count === 0) {
            $this->cache->put($cacheKey, 1, $window);
            $count = 1;
        }

        $resetAt = ($bucket + 1) * $window;

        return new RateLimitResult(
            $count <= $max,
            $max,
            max(0, $max - $count),
            $resetAt,
        );
    }
}
