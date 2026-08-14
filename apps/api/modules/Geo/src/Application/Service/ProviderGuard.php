<?php

declare(strict_types=1);

namespace EruoFood\Geo\Application\Service;

use EruoFood\Geo\Application\Port\CircuitBreakerPort;
use EruoFood\Geo\Application\Port\ProviderTelemetry;
use EruoFood\Geo\Domain\Event\GeoProviderDegraded;
use EruoFood\Geo\Domain\Exception\GeoAddressNotFound;
use EruoFood\Geo\Domain\Exception\GeoProviderUnavailable;
use EruoFood\Geo\Domain\Exception\GeoQuotaExceeded;
use EruoFood\Shared\Domain\EventBus;
use Throwable;

/**
 * Everything that must happen around a billable provider call, in one place.
 *
 * Quota, circuit breaker, timing, telemetry and failure accounting are the same
 * four lines at every call site, and a call site that forgets one of them is
 * indistinguishable from one that does not — until the bill arrives or an
 * outage takes checkout down with it. Centralising them means a new capability
 * inherits all of it by construction.
 *
 * The ordering is deliberate:
 *
 * 1. **Quota first.** A platform already over budget should not spend latency
 *    discovering that.
 * 2. **Circuit second.** A provider known to be failing fails immediately, so
 *    the caller reaches its fallback in microseconds rather than after a
 *    timeout — and does not pay for a call that will not work.
 * 3. **The call, timed.**
 * 4. **Telemetry always**, on both paths.
 */
final readonly class ProviderGuard
{
    public function __construct(
        private CircuitBreakerPort $breaker,
        private ProviderTelemetry $telemetry,
        private EventBus $events,
        private int $dailyQuota,
        private int $failureThreshold,
    ) {
    }

    /**
     * Run a provider call with the full guard around it.
     *
     * @template T
     *
     * @param callable(): T $operation
     * @return T
     */
    public function call(string $provider, string $capability, callable $operation): mixed
    {
        $circuit = $provider.':'.$capability;

        $this->assertWithinQuota($provider, $capability);

        if ($this->breaker->isOpen($circuit)) {
            $this->telemetry->record($provider, $capability, false, false, null, 'CIRCUIT_OPEN');

            throw GeoProviderUnavailable::circuitOpen($capability);
        }

        $startedAt = microtime(true);

        try {
            $result = $operation();
        } catch (GeoAddressNotFound $e) {
            // The provider worked; the address does not exist. Counting this as
            // a failure would open the circuit on a run of typos and take
            // geocoding down for everybody.
            $this->telemetry->record($provider, $capability, true, false, $this->elapsed($startedAt), 'NOT_FOUND');

            throw $e;
        } catch (Throwable $e) {
            $this->telemetry->record($provider, $capability, false, false, $this->elapsed($startedAt), $this->codeFor($e));

            $failures = $this->breaker->recordFailure($circuit);

            if ($failures >= $this->failureThreshold) {
                // Published once the circuit opens, so the command centre learns
                // about a degraded provider from the platform rather than from
                // customers.
                $this->events->publish(new GeoProviderDegraded($provider, $capability, $failures));
            }

            throw $e;
        }

        $this->telemetry->record($provider, $capability, true, false, $this->elapsed($startedAt));
        $this->breaker->recordSuccess($circuit);

        return $result;
    }

    /** Record that an answer came from cache — free, and worth being able to prove. */
    public function recordCacheHit(string $provider, string $capability): void
    {
        $this->telemetry->record($provider, $capability, true, true);
    }

    private function assertWithinQuota(string $provider, string $capability): void
    {
        if ($this->dailyQuota <= 0) {
            return;
        }

        if ($this->telemetry->billableCallsToday() < $this->dailyQuota) {
            return;
        }

        $this->telemetry->record($provider, $capability, false, false, null, 'QUOTA_EXCEEDED');

        throw GeoQuotaExceeded::forPlatform();
    }

    /**
     * A short normalised code, never the provider's own message.
     *
     * Provider errors quote the request, and for a geocode the request is
     * somebody's home address. This table is what the cost and health dashboards
     * group by, and it is safe to export.
     */
    private function codeFor(Throwable $e): string
    {
        return match (true) {
            $e instanceof GeoQuotaExceeded => 'QUOTA_EXCEEDED',
            $e instanceof GeoProviderUnavailable => 'PROVIDER_UNAVAILABLE',
            default => 'UNEXPECTED_ERROR',
        };
    }

    private function elapsed(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
