<?php

declare(strict_types=1);

namespace EruoFood\Search\Infrastructure\Cache;

use Closure;
use EruoFood\Search\Application\Port\SearchCache;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Search-result cache backed by the framework cache. Entries are tagged where
 * the store supports it so the index manager can invalidate the whole search
 * namespace on a reindex; otherwise it degrades to per-key expiry.
 */
final readonly class LaravelSearchCache implements SearchCache
{
    public function __construct(private CacheRepository $cache)
    {
    }

    public function remember(string $key, int $ttlSeconds, callable $resolver): mixed
    {
        return $this->cache->remember($key, $ttlSeconds, Closure::fromCallable($resolver));
    }

    public function forget(string $key): void
    {
        $this->cache->forget($key);
    }

    public function flush(): void
    {
        $this->cache->clear();
    }
}
