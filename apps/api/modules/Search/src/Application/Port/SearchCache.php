<?php

declare(strict_types=1);

namespace EruoFood\Search\Application\Port;

/**
 * A small read-through cache for search results, keyed by the query's
 * deterministic cache key. Decouples the pipeline from the framework cache; a
 * null adapter disables caching (e.g. in tests).
 */
interface SearchCache
{
    /**
     * @param callable(): mixed $resolver
     */
    public function remember(string $key, int $ttlSeconds, callable $resolver): mixed;

    public function forget(string $key): void;

    public function flush(): void;
}
