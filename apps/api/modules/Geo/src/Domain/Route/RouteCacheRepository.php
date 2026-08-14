<?php

declare(strict_types=1);

namespace EruoFood\Geo\Domain\Route;

use DateTimeImmutable;

/**
 * Durable route storage, sitting behind the Redis cache.
 *
 * Exists for one specific moment: the provider is down, Redis has been flushed,
 * and a customer is checking out. A route from this morning is a defensible
 * basis for a fee — the alternative at that point is a straight-line guess,
 * which is not.
 */
interface RouteCacheRepository
{
    public function findByKey(string $cacheKey): ?Route;

    /** The most recent usable route for a key, however old — the caller judges the age. */
    public function findByKeyRegardlessOfAge(string $cacheKey): ?Route;

    public function store(string $cacheKey, Route $route): void;

    /** Remove entries calculated before $before. Returns rows deleted. */
    public function purgeOlderThan(DateTimeImmutable $before): int;
}
