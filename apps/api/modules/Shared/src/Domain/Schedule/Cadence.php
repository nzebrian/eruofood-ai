<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\Schedule;

/**
 * How often a scheduled task runs.
 *
 * A closed set rather than a free-form cron string, because a cron expression
 * in a module's service provider is unreviewable — nobody catches `* * * * *`
 * where `0 * * * *` was meant until the job has run three thousand times.
 *
 * The scheduler runs in UTC (see `SystemClock`). A task that must happen at a
 * *local* hour — a merchant's end-of-day, a morning reminder — should run
 * frequently and decide per record using
 * {@see \EruoFood\Shared\Domain\Time\WallClock}, rather than being scheduled at
 * one wall-clock hour that is correct for a single timezone.
 */
enum Cadence: string
{
    case EveryMinute = 'every_minute';
    case EveryFiveMinutes = 'every_five_minutes';
    case EveryFifteenMinutes = 'every_fifteen_minutes';
    case Hourly = 'hourly';
    case Daily = 'daily';

    /** How long a run may take before an overlapping run is allowed, in minutes. */
    public function overlapGuardMinutes(): int
    {
        return match ($this) {
            self::EveryMinute => 5,
            self::EveryFiveMinutes => 15,
            self::EveryFifteenMinutes => 30,
            self::Hourly => 60,
            self::Daily => 240,
        };
    }
}
