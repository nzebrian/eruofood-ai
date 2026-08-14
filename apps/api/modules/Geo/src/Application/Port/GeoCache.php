<?php

declare(strict_types=1);

namespace EruoFood\Geo\Application\Port;

/**
 * The mapping cache.
 *
 * A port rather than the cache facade so that TTL policy stays testable and so
 * an array-backed implementation can drive the suite without Redis. The keys
 * are built by callers, because only they know which inputs are significant —
 * a route key must include travel mode, a geocode key must not.
 */
interface GeoCache
{
    /**
     * Whatever was stored under this key, or null for a miss.
     *
     * The value is any array, not a keyed one: a geocode caches a record but a
     * list of autocomplete suggestions caches a list, and both belong here.
     * Callers must therefore validate the shape they get back rather than
     * assume it — an entry written by an earlier release deserialises into
     * whatever that release stored, and treating that as a miss costs one
     * provider call, where trusting it costs a fatal error on warm cache.
     *
     * @return array<array-key, mixed>|null
     */
    public function get(string $key): ?array;

    /** @param array<array-key, mixed> $value */
    public function put(string $key, array $value, int $ttlSeconds): void;

    public function forget(string $key): void;
}
