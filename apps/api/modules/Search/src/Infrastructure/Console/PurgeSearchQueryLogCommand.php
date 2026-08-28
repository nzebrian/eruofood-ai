<?php

declare(strict_types=1);

namespace EruoFood\Search\Infrastructure\Console;

use EruoFood\Search\Domain\Analytics\SearchAnalyticsRepository;
use Illuminate\Console\Command;
use Throwable;

/**
 * Enforces the retention window on the search query log (M40-SEC-001).
 *
 * ## What this removes and why
 *
 * `search_query_log` stores the VERBATIM text somebody typed, alongside the
 * `user_id` who typed it. It is written on every executed search. Before M40
 * nothing ever removed a row, so the platform held an attributable record of
 * what every user had searched for, indefinitely.
 *
 * M39 constrained what is *published* from this table — public trending and
 * suggestions now require a public scope and a minimum occurrence count. That
 * was a disclosure control and said nothing about storage. This is the storage
 * half: past the declared window the row goes.
 *
 * ## What it does NOT print
 *
 * Deliberately: no terms, no user ids. This command exists because query
 * strings are sensitive, so a purge that echoed them into operator logs on its
 * way to deleting them would defeat its own purpose. Only counts and
 * timestamps are emitted.
 *
 * ## Dry run
 *
 * `DeletionMode::Destroy` is irreversible — {@see
 * \EruoFood\Shared\Domain\DataLifecycle\DeletionMode::isReversible()} says so —
 * which is exactly why `--dry-run` reports the eligible count without touching
 * a row.
 */
final class PurgeSearchQueryLogCommand extends Command
{
    protected $signature = 'search:purge-query-log
        {--days= : Override the configured retention window}
        {--chunk= : Rows to delete per statement}
        {--dry-run : Report what would be removed, and remove nothing}';

    protected $description = 'Remove search query-log entries past their retention window';

    public function handle(SearchAnalyticsRepository $analytics): int
    {
        $days = (int) ($this->option('days') ?? config('search.query_log_retention_days', 90));

        if ($days <= 0) {
            // A window of zero would delete the entire log, including the
            // search somebody ran a second ago. Misconfiguration must fail
            // loudly rather than quietly destroy the table.
            $this->error('Retention window must be a positive number of days; refusing to purge.');

            return self::FAILURE;
        }

        $chunk = (int) ($this->option('chunk') ?? config('search.query_log_purge_chunk', 1000));

        if ($chunk <= 0) {
            $this->error('Chunk size must be a positive number of rows.');

            return self::FAILURE;
        }

        // The framework clock, not `new DateTimeImmutable()`. Retention is
        // entirely a statement about elapsed time, so the cutoff has to be
        // something a test can control — otherwise the only way to exercise a
        // 90-day window is to wait 90 days, and the boundary behaviour of an
        // irreversible delete goes untested.
        $cutoff = now()->toDateTimeImmutable()->modify(sprintf('-%d days', $days));

        try {
            $eligible = $analytics->countQueriesBefore($cutoff);

            if ($this->option('dry-run')) {
                $this->line(sprintf(
                    'Dry run: %d query-log row(s) recorded before %s are eligible. Nothing was deleted.',
                    $eligible,
                    $cutoff->format(DATE_ATOM),
                ));

                return self::SUCCESS;
            }

            $removed = $analytics->purgeQueriesBefore($cutoff, $chunk);
        } catch (Throwable $e) {
            // No swallowing. A purge that failed silently would look identical
            // to one that found nothing to do, and the retention claim would
            // quietly become false.
            $this->error(sprintf('Query-log purge failed: %s', $e->getMessage()));

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Purged %d of %d eligible query-log row(s) recorded before %s (retention %d days).',
            $removed,
            $eligible,
            $cutoff->format('Y-m-d H:i:s'),
            $days,
        ));

        return self::SUCCESS;
    }
}
