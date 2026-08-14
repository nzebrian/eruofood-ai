<?php

declare(strict_types=1);

namespace EruoFood\Geo\Application\Port;

/**
 * Records that a provider call happened.
 *
 * Mapping APIs bill per request, so the failure mode of a runaway client is an
 * invoice rather than a crash. Without this the first sign of trouble is a
 * monthly bill; with it, call volume, cache effectiveness and failure rates are
 * visible as they happen.
 *
 * Implementations record **no coordinates and no address text** — this is
 * operational data, queried and exported by people with no business knowing
 * where a given customer lives.
 */
interface ProviderTelemetry
{
    public function record(
        string $provider,
        string $capability,
        bool $succeeded,
        bool $servedFromCache,
        ?int $durationMs = null,
        ?string $failureCode = null,
    ): void;

    /** Billable calls made today, for the platform quota check. */
    public function billableCallsToday(): int;
}
