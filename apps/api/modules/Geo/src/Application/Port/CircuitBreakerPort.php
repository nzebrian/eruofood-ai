<?php

declare(strict_types=1);

namespace EruoFood\Geo\Application\Port;

/**
 * A circuit breaker, as the application layer needs to see one.
 *
 * A port rather than a direct dependency on the cache-backed implementation, so
 * the services below stay testable without a cache and so the breaker's storage
 * — today a shared cache, tomorrow possibly something with better semantics —
 * is an infrastructure decision rather than an application one.
 */
interface CircuitBreakerPort
{
    /** Whether calls to this circuit are currently being refused outright. */
    public function isOpen(string $circuit): bool;

    /** Record a failure; returns the new consecutive-failure count. */
    public function recordFailure(string $circuit): int;

    public function recordSuccess(string $circuit): void;

    public function consecutiveFailures(string $circuit): int;
}
