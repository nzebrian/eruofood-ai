<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Infrastructure\RateLimit;

use EruoFood\PublicApi\Application\Port\QuotaStore;
use Illuminate\Contracts\Cache\Repository as Cache;

/** Quota counters over a cache store (Redis in production). */
final readonly class CacheQuotaStore implements QuotaStore
{
    public function __construct(private Cache $cache)
    {
    }

    public function increment(string $key, int $ttlSeconds): int
    {
        $this->cache->add($key, 0, $ttlSeconds);
        $count = (int) $this->cache->increment($key);
        if ($count === 0) {
            $this->cache->put($key, 1, $ttlSeconds);
            $count = 1;
        }

        return $count;
    }

    public function current(string $key): int
    {
        return (int) ($this->cache->get($key) ?? 0);
    }
}
