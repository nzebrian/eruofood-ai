<?php

declare(strict_types=1);

namespace EruoFood\Geo\Infrastructure\Provider;

use EruoFood\Geo\Application\Port\CircuitBreakerPort;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Stops hammering a provider that is already failing.
 *
 * After a threshold of consecutive failures the circuit opens and calls are
 * refused immediately for a cool-off period. Two things this buys: latency,
 * because a request that would have waited for a timeout fails at once and
 * moves down the fallback chain; and money, because failed calls are still
 * billable at some providers.
 *
 * State lives in the shared cache rather than in memory, so every web process
 * sees the same circuit. A per-process breaker in a pool of twenty workers is
 * twenty breakers, and twenty times the threshold before anything opens.
 */
final readonly class CircuitBreaker implements CircuitBreakerPort
{
    public function __construct(
        private CacheRepository $cache,
        private int $failureThreshold = 5,
        private int $openSeconds = 60,
        private bool $enabled = true,
    ) {
    }

    public function isOpen(string $circuit): bool
    {
        if (! $this->enabled) {
            return false;
        }

        return (bool) $this->cache->get($this->openKey($circuit), false);
    }

    /** Returns the new consecutive-failure count. */
    public function recordFailure(string $circuit): int
    {
        if (! $this->enabled) {
            return 0;
        }

        $failures = (int) $this->cache->get($this->countKey($circuit), 0) + 1;

        // The counter's own TTL is the cool-off: an isolated failure every ten
        // minutes is not a broken provider and should not accumulate towards
        // opening the circuit.
        $this->cache->put($this->countKey($circuit), $failures, $this->openSeconds * 2);

        if ($failures >= $this->failureThreshold) {
            $this->cache->put($this->openKey($circuit), true, $this->openSeconds);
        }

        return $failures;
    }

    public function recordSuccess(string $circuit): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->cache->forget($this->countKey($circuit));
        $this->cache->forget($this->openKey($circuit));
    }

    public function consecutiveFailures(string $circuit): int
    {
        return (int) $this->cache->get($this->countKey($circuit), 0);
    }

    private function countKey(string $circuit): string
    {
        return 'geo:circuit:'.$circuit.':failures';
    }

    private function openKey(string $circuit): string
    {
        return 'geo:circuit:'.$circuit.':open';
    }
}
