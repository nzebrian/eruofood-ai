<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Application\Port;

/**
 * Tracks cumulative request counts per client per calendar period (day/month)
 * for quota enforcement and usage reporting. Redis-backed in production.
 */
interface QuotaStore
{
    /** Increment and return the new count for the period bucket. */
    public function increment(string $key, int $ttlSeconds): int;

    /** Read the current count without incrementing. */
    public function current(string $key): int;
}
