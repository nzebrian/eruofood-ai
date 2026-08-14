<?php

declare(strict_types=1);

namespace EruoFood\Geo\Infrastructure\Provider;

use EruoFood\Geo\Application\Port\ProviderTelemetry;
use EruoFood\Geo\Infrastructure\Persistence\Eloquent\Model\ProviderRequestModel;
use Illuminate\Support\Str;
use Throwable;

/**
 * Writes one row per provider call.
 *
 * Records no coordinates and no address text. This table will be queried,
 * exported and graphed by people running the platform, none of whom need to
 * know where a particular customer lives — and telemetry has a way of
 * outliving the system it was collected from.
 *
 * Failures to record are swallowed. Telemetry that can break a delivery quote
 * is worse than telemetry that occasionally misses a row.
 */
final readonly class EloquentProviderTelemetry implements ProviderTelemetry
{
    public function record(
        string $provider,
        string $capability,
        bool $succeeded,
        bool $servedFromCache,
        ?int $durationMs = null,
        ?string $failureCode = null,
    ): void {
        try {
            ProviderRequestModel::query()->create([
                'id' => (string) Str::orderedUuid(),
                'provider' => $provider,
                'capability' => $capability,
                'succeeded' => $succeeded,
                'served_from_cache' => $servedFromCache,
                'duration_ms' => $durationMs,
                'failure_code' => $failureCode,
                'requested_at' => now(),
            ]);
        } catch (Throwable) {
            // Never let bookkeeping break the operation it is describing.
        }
    }

    public function billableCallsToday(): int
    {
        try {
            return ProviderRequestModel::query()
                ->where('served_from_cache', false)
                ->where('requested_at', '>=', now()->startOfDay())
                ->count();
        } catch (Throwable) {
            // If the ledger cannot be read, do not conclude the budget is spent
            // and start refusing traffic.
            return 0;
        }
    }
}
