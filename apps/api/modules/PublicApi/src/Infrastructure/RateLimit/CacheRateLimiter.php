<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Infrastructure\RateLimit;

use EruoFood\PublicApi\Application\Port\RateLimiter;
use EruoFood\PublicApi\Domain\ValueObject\RateLimitResult;
use Illuminate\Contracts\Cache\Repository as Cache;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fixed-window rate limiter over a cache store (Redis in production, array in
 * tests). The counter key is bucketed by window so it resets automatically; the
 * increment is atomic on stores that support it.
 *
 * Resilience: if the backend (Redis) is unreachable, the limiter **fails closed**
 * — it denies the request with a short, deterministic reset window rather than
 * letting an uncaught connection error surface as a 500 or, worse, allowing
 * unlimited traffic. Failing closed preserves the security guarantee (no
 * bypass); availability during a Redis outage is protected separately by the
 * readiness probe pulling the pod from the load balancer and by production Redis
 * HA (see docs/REDIS_RESILIENCE.md). We never fail *open* for security limits.
 */
final readonly class CacheRateLimiter implements RateLimiter
{
    public function __construct(
        private Cache $cache,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function hit(string $key, int $max, int $windowSeconds): RateLimitResult
    {
        $window = max(1, $windowSeconds);
        $bucket = (int) floor(time() / $window);
        $cacheKey = sprintf('%s:%d', $key, $bucket);
        $resetAt = ($bucket + 1) * $window;

        try {
            $this->cache->add($cacheKey, 0, $window);
            $count = (int) $this->cache->increment($cacheKey);
            // Defensive: some drivers return false/0 if the key just expired.
            if ($count === 0) {
                $this->cache->put($cacheKey, 1, $window);
                $count = 1;
            }
        } catch (Throwable $e) {
            // Backend unavailable — fail closed (deny), never open.
            $this->logger?->warning('Rate-limit backend unavailable; failing closed.', [
                'key' => $key,
                'exception' => $e->getMessage(),
            ]);

            return new RateLimitResult(false, $max, 0, $resetAt);
        }

        return new RateLimitResult(
            $count <= $max,
            $max,
            max(0, $max - $count),
            $resetAt,
        );
    }
}
