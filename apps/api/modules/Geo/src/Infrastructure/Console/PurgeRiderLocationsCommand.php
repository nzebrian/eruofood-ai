<?php

declare(strict_types=1);

namespace EruoFood\Geo\Infrastructure\Console;

use EruoFood\Geo\Domain\Rider\RiderLocationRepository;
use Illuminate\Console\Command;
use Throwable;

/**
 * Enforces the retention window on `geo_rider_locations` (M42).
 *
 * ## What this actually removes
 *
 * Worth being precise, because the policy's wording invites a wrong reading.
 * `geo.rider_locations` says the data is "worthless once the delivery ends, and
 * a movement history thereafter" — but this table is **not** a movement history.
 * `rider_id` is the primary key and {@see
 * \EruoFood\Geo\Infrastructure\Persistence\Eloquent\EloquentRiderLocationRepository::save()}
 * upserts, so there is exactly one row per rider: their latest position.
 *
 * So this does not trim a trail. It deletes the *last known position* of a rider
 * who has not reported since the cutoff — a coordinate with no remaining
 * dispatch value that has quietly become a record of where somebody was. A
 * rider who is still working overwrites their row continuously and is never
 * eligible.
 *
 * ## What it does not print
 *
 * No coordinates, no rider ids, no user ids. A command that exists to stop
 * storing where people were should not read those positions out to a terminal
 * on its way to deleting them. Counts and timestamps only.
 */
final class PurgeRiderLocationsCommand extends Command
{
    protected $signature = 'geo:purge-rider-locations
        {--days= : Override the configured retention window}
        {--chunk= : Rows to delete per statement}
        {--dry-run : Report what would be removed, and remove nothing}';

    protected $description = 'Remove rider positions last recorded past their retention window';

    public function handle(RiderLocationRepository $riders): int
    {
        $days = (int) ($this->option('days') ?? config('geo.rider_location_retention_days', 30));

        if ($days <= 0) {
            // Zero would delete every stored position including the fix that
            // arrived a second ago, taking live dispatch down with it.
            // Misconfiguration must fail loudly, not quietly empty the table.
            $this->error('Retention window must be a positive number of days; refusing to purge.');

            return self::FAILURE;
        }

        $chunk = (int) ($this->option('chunk') ?? config('geo.rider_location_purge_chunk', 1000));

        if ($chunk <= 0) {
            $this->error('Chunk size must be a positive number of rows.');

            return self::FAILURE;
        }

        // The framework clock, not `new DateTimeImmutable()`. Retention is
        // entirely a claim about elapsed time, so the cutoff has to be something
        // a test can control — otherwise exercising a 30-day boundary on an
        // irreversible delete means waiting 30 days.
        $cutoff = now()->toDateTimeImmutable()->modify(sprintf('-%d days', $days));

        try {
            $eligible = $riders->countRecordedBefore($cutoff);

            if ($this->option('dry-run')) {
                $this->line(sprintf(
                    'Dry run: %d rider position(s) last recorded before %s are eligible. Nothing was deleted.',
                    $eligible,
                    $cutoff->format(DATE_ATOM),
                ));

                return self::SUCCESS;
            }

            $removed = $riders->purgeRecordedBefore($cutoff, $chunk);
        } catch (Throwable $e) {
            $this->error(sprintf('Rider-location purge failed: %s', $e->getMessage()));

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Purged %d of %d rider position(s) last recorded before %s (retention %d days).',
            $removed,
            $eligible,
            $cutoff->format('Y-m-d H:i:s'),
            $days,
        ));

        return self::SUCCESS;
    }
}
