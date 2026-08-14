<?php

declare(strict_types=1);

namespace EruoFood\Geo\Application\Port;

/**
 * Per-caller request limiting for the mapping capabilities.
 *
 * Mapping APIs bill per request, so the failure mode of a client stuck in a
 * loop is an invoice rather than a crash — which means nobody notices until the
 * month ends. A limit is the difference between a bug and an incident.
 */
interface GeoRateLimiter
{
    /**
     * Consume one unit against a caller's per-minute allowance.
     *
     * Returns false when the allowance is spent. Deliberately a consume rather
     * than a check, so two concurrent requests cannot both read "one left".
     */
    public function attempt(string $key, int $maxPerMinute): bool;

    /** How many units remain in the current window, for a Retry-After header. */
    public function remaining(string $key, int $maxPerMinute): int;
}
