<?php

declare(strict_types=1);

namespace EruoFood\Search\Infrastructure\Cache;

use Closure;
use EruoFood\Search\Application\Port\SearchCache;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Throwable;

/**
 * Search-result cache, isolated from the rest of the application (M38-CACHE-001).
 *
 * ## What this replaces
 *
 * `flush()` used to call `$this->cache->clear()` — a store-wide flush. The
 * class was bound to the DEFAULT cache repository, so with `CACHE_STORE=redis`
 * a single reindex evicted rate-limit counters, config and route caches and
 * anything else sharing the store. `SearchIndexManager` called it on every
 * document, so `reindexAll()` over N documents issued N whole-store flushes.
 *
 * ## How invalidation works now
 *
 * Every key carries a Search-owned version number:
 *
 *     eruofood:search:v7:<hash>
 *
 * Invalidating means incrementing the version. Old keys become unreachable
 * immediately and expire on their own TTL; nothing outside the Search namespace
 * is touched, and the operation is one INCR rather than a FLUSH — so a backfill
 * can invalidate once at the end instead of N times.
 *
 * Tags are deliberately not used: they are unavailable on the `file` and
 * `database` stores this repository supports in local and CI environments, and
 * a strategy that silently degrades on some stores is the kind of environment
 * -dependent behaviour M38 exists to remove.
 */
final class LaravelSearchCache implements SearchCache
{
    /** Where the version counter lives. Never itself versioned. */
    private const VERSION_KEY = ':version';

    private ?int $version = null;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly string $prefix = 'eruofood:search',
    ) {
    }

    public function remember(string $key, int $ttlSeconds, callable $resolver): mixed
    {
        return $this->cache->remember(
            $this->namespaced($key),
            $ttlSeconds,
            Closure::fromCallable($resolver),
        );
    }

    public function forget(string $key): void
    {
        $this->cache->forget($this->namespaced($key));
    }

    /**
     * Invalidate every cached search result — and nothing else.
     *
     * There is no call to `clear()`, `flush()` or any other whole-store
     * operation anywhere in this class, and
     * `modules/Search/tests/Feature/SearchCacheIsolationTest.php` asserts that
     * an unrelated key written before a reindex is still readable after it.
     */
    public function flush(): void
    {
        try {
            $next = $this->cache->increment($this->prefix.self::VERSION_KEY);
            $this->version = is_int($next) ? $next : null;
        } catch (Throwable) {
            // A store that cannot increment (or a transient failure) must not
            // leave stale results being served as if they were fresh. Forget
            // the pointer so the next read re-derives it, and fall back to a
            // time-based version, which is monotonic enough to invalidate.
            $this->version = null;

            try {
                $this->cache->forever($this->prefix.self::VERSION_KEY, time());
            } catch (Throwable) {
                // Cache unavailable entirely. Reads will miss and recompute,
                // which is correct-but-slow rather than stale-but-fast.
                $this->version = null;
            }
        }
    }

    /** The namespace currently in force, for diagnostics and tests. */
    public function namespacePrefix(): string
    {
        return $this->prefix.':v'.$this->currentVersion();
    }

    private function namespaced(string $key): string
    {
        return $this->namespacePrefix().':'.$key;
    }

    private function currentVersion(): int
    {
        if ($this->version !== null) {
            return $this->version;
        }

        try {
            $stored = $this->cache->get($this->prefix.self::VERSION_KEY);
        } catch (Throwable) {
            // Treat an unreadable pointer as version 0 rather than guessing a
            // higher one: a wrong-but-stable namespace only costs cache misses.
            return $this->version = 0;
        }

        return $this->version = is_int($stored) ? $stored : (is_numeric($stored) ? (int) $stored : 0);
    }
}
