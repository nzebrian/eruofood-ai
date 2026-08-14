<?php

declare(strict_types=1);

namespace EruoFood\Geo\Infrastructure\RateLimit;

use EruoFood\Geo\Application\Port\GeoRateLimiter;
use Illuminate\Cache\RateLimiter;
use Throwable;

/**
 * Per-caller limiting over Laravel's cache-backed rate limiter.
 *
 * Shared state, so the limit is the limit across every web process rather than
 * per worker — a per-process counter in a pool of twenty is twenty times the
 * allowance, which is exactly the case a cost control exists to prevent.
 *
 * A cache failure allows the request. That is the deliberate direction: the
 * limiter protects a budget, and an unreachable Redis should not stop customers
 * from saving an address. The daily platform quota in {@see \EruoFood\Geo\Application\Service\ProviderGuard}
 * is the backstop that still holds when this one is blind.
 */
final readonly class CacheGeoRateLimiter implements GeoRateLimiter
{
    public function __construct(private RateLimiter $limiter)
    {
    }

    public function attempt(string $key, int $maxPerMinute): bool
    {
        if ($maxPerMinute <= 0) {
            return true;
        }

        try {
            if ($this->limiter->tooManyAttempts($this->prefixed($key), $maxPerMinute)) {
                return false;
            }

            $this->limiter->hit($this->prefixed($key), 60);

            return true;
        } catch (Throwable) {
            return true;
        }
    }

    public function remaining(string $key, int $maxPerMinute): int
    {
        if ($maxPerMinute <= 0) {
            return PHP_INT_MAX;
        }

        try {
            return max(0, $this->limiter->remaining($this->prefixed($key), $maxPerMinute));
        } catch (Throwable) {
            return $maxPerMinute;
        }
    }

    private function prefixed(string $key): string
    {
        return 'geo:rl:'.$key;
    }
}
