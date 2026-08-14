<?php

declare(strict_types=1);

namespace EruoFood\Geo\Infrastructure\Cache;

use EruoFood\Geo\Application\Port\GeoCache;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Throwable;

/**
 * The mapping cache, over Laravel's cache store (Redis in production).
 *
 * Every read and write is wrapped, and a cache failure is treated as a miss.
 * That choice matters: the cache exists to save money and latency, and an
 * unreachable Redis should make geocoding expensive, not broken. A throwing
 * cache would take checkout down to protect a cost optimisation.
 */
final readonly class RedisGeoCache implements GeoCache
{
    public function __construct(
        private CacheRepository $cache,
        private string $prefix,
        private bool $enabled = true,
    ) {
    }

    public function get(string $key): ?array
    {
        if (! $this->enabled) {
            return null;
        }

        try {
            $value = $this->cache->get($this->prefixed($key));
        } catch (Throwable) {
            return null;
        }

        return is_array($value) ? $value : null;
    }

    public function put(string $key, array $value, int $ttlSeconds): void
    {
        if (! $this->enabled || $ttlSeconds <= 0) {
            return;
        }

        try {
            $this->cache->put($this->prefixed($key), $value, $ttlSeconds);
        } catch (Throwable) {
            // A cache we cannot write to is a cache miss next time, not an error.
        }
    }

    public function forget(string $key): void
    {
        try {
            $this->cache->forget($this->prefixed($key));
        } catch (Throwable) {
            // Nothing useful to do; the entry will expire.
        }
    }

    private function prefixed(string $key): string
    {
        return $this->prefix.':'.$key;
    }
}
