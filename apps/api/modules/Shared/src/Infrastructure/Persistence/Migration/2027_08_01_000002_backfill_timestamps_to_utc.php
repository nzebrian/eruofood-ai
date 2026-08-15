<?php

declare(strict_types=1);

use EruoFood\Shared\Domain\Time\TimestampColumnClassifier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The UTC cutover: move every stored timestamp from local wall-clock to UTC.
 *
 * ## What was wrong
 *
 * `config/app.php` set the application timezone to `Africa/Lagos`, which is
 * what PHP's default timezone becomes, which is what `now()` returns. Every one
 * of the platform's timestamp columns is `timestamp` *without* time zone, and
 * none of them has a database-side default — so every value in the database was
 * written by PHP as Lagos wall-clock. PostgreSQL itself runs in UTC. The two
 * have disagreed by an hour since the first row was written.
 *
 * Nothing broke, because everything wrote and read with the same wrong
 * assumption. It breaks the moment a second worker, region or market does not
 * share it — and by then the wrong values are historical.
 *
 * ## Why a fixed shift is correct here, and would not be everywhere
 *
 * `Africa/Lagos` is UTC+1 and **has never observed daylight saving**. So the
 * offset is the same for every row regardless of date, and a single
 * `- 1 hour` is exact rather than an approximation. A platform migrating out of
 * a DST-observing zone could not do this — it would need a per-row conversion
 * and would still be ambiguous for times inside a fall-back overlap.
 *
 * The offset is read from `shared.timezone.backfill_from` rather than hardcoded
 * so the value that ran is visible in configuration, and recorded per row in
 * `shared_timezone_backfill_log` so it is visible afterwards.
 *
 * ## Safety
 *
 * - **Runs once.** A forward row in the log table blocks a second application.
 *   Double-shifting is the one mistake here that looks like data corruption
 *   rather than a failed migration.
 * - **Reversible.** `down()` shifts back and records the reverse.
 * - **Counted.** Per table and column, into the log table and the application
 *   log.
 * - **Classified, not blanket.** Only columns that {@see TimestampColumnClassifier}
 *   confirms were written from the application clock are converted. A first
 *   version of this migration shifted every `timestamp` column it discovered —
 *   262 of them — which would have moved rider document expiries and settlement
 *   period boundaries by a day. Provenance decides, not SQL type; everything
 *   excluded is logged with its category and reason.
 * - **Empty databases are a no-op.** On `migrate:fresh` there are no rows, so
 *   the test suites see nothing but the log table.
 */
return new class () extends Migration {
    /** Columns whose stored value never came from the application clock. */
    private const SKIP = [
        // Its own audit trail, written with the database clock by design.
        'shared_timezone_backfill_log',
        // Laravel's own bookkeeping: `migrations` has no timestamp column, and
        // these are operational rather than domain data.
        'migrations',
    ];

    public function up(): void
    {
        $offset = $this->offsetSeconds();

        if ($offset === 0) {
            Log::info('[timezone-backfill] Source timezone is already UTC; nothing to shift.');

            return;
        }

        if ($this->alreadyApplied()) {
            Log::warning('[timezone-backfill] Forward backfill already recorded; refusing to shift a second time.');

            return;
        }

        $this->shift(-$offset, 'forward');
    }

    public function down(): void
    {
        $offset = $this->offsetSeconds();

        if ($offset === 0 || ! $this->alreadyApplied()) {
            return;
        }

        $this->shift($offset, 'reverse');

        // Clear the guard so a re-run of up() is possible after a rollback.
        DB::table('shared_timezone_backfill_log')->delete();
    }

    /**
     * Apply $seconds to every timezone-naive timestamp column, and record it.
     */
    private function shift(int $seconds, string $direction): void
    {
        $classifier = new TimestampColumnClassifier();

        $totalRows = 0;
        $converted = 0;
        $excluded = [];

        foreach ($this->timestampColumns() as [$table, $column]) {
            $classification = $classifier->classify($table, $column);

            if (! $classification->isConverted()) {
                // Recorded, not silently passed over. A column nobody can
                // account for afterwards is how a half-converted database
                // happens.
                $excluded[$classification->category->value][] = $classification->qualifiedName();

                continue;
            }

            $affected = $this->shiftColumn($table, $column, $seconds);
            $converted++;
            $totalRows += $affected;

            DB::table('shared_timezone_backfill_log')->insert([
                'table_name' => $table,
                'column_name' => $column,
                'direction' => $direction,
                'offset_seconds' => $seconds,
                'rows_affected' => $affected,
                'recorded_at' => now(),
            ]);
        }

        Log::info('[timezone-backfill] Completed.', [
            'direction' => $direction,
            'offset_seconds' => $seconds,
            'columns_converted' => $converted,
            'rows' => $totalRows,
            'excluded' => array_map(count(...), $excluded),
            'excluded_columns' => $excluded,
        ]);
    }

    private function shiftColumn(string $table, string $column, int $seconds): int
    {
        $quotedTable = $this->quote($table);
        $quotedColumn = $this->quote($column);

        if ($this->isPostgres()) {
            // Interval arithmetic on `timestamp without time zone` is plain
            // field arithmetic — no implicit zone conversion is involved, which
            // is exactly what we want: we are relabelling values, not
            // converting them.
            return DB::update(
                "UPDATE {$quotedTable} SET {$quotedColumn} = {$quotedColumn} + (? * INTERVAL '1 second') WHERE {$quotedColumn} IS NOT NULL",
                [$seconds],
            );
        }

        return DB::update(
            "UPDATE {$quotedTable} SET {$quotedColumn} = datetime({$quotedColumn}, ?) WHERE {$quotedColumn} IS NOT NULL",
            [sprintf('%+d seconds', $seconds)],
        );
    }

    /**
     * Every timezone-naive timestamp column in the schema.
     *
     * Discovered rather than listed. A hardcoded list would be correct on the
     * day it was written and silently incomplete after the next migration adds
     * a column — and a timestamp this process misses is one that stays an hour
     * wrong for ever, with nothing to indicate it.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function timestampColumns(): array
    {
        return $this->isPostgres()
            ? $this->postgresTimestampColumns()
            : $this->sqliteTimestampColumns();
    }

    /** @return list<array{0: string, 1: string}> */
    private function postgresTimestampColumns(): array
    {
        /** @var list<object{table_name: string, column_name: string}> $rows */
        $rows = DB::select(
            <<<'SQL'
            SELECT c.table_name, c.column_name
            FROM information_schema.columns c
            JOIN information_schema.tables t
              ON t.table_schema = c.table_schema AND t.table_name = c.table_name
            WHERE c.table_schema = current_schema()
              AND t.table_type = 'BASE TABLE'
              AND c.data_type = 'timestamp without time zone'
            ORDER BY c.table_name, c.column_name
            SQL
        );

        $columns = [];

        foreach ($rows as $row) {
            if (in_array($row->table_name, self::SKIP, true)) {
                continue;
            }

            $columns[] = [$row->table_name, $row->column_name];
        }

        return $columns;
    }

    /** @return list<array{0: string, 1: string}> */
    private function sqliteTimestampColumns(): array
    {
        /** @var list<object{name: string}> $tables */
        $tables = DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'");

        $columns = [];

        foreach ($tables as $table) {
            if (in_array($table->name, self::SKIP, true)) {
                continue;
            }

            /** @var list<object{name: string, type: string}> $info */
            $info = DB::select('PRAGMA table_info('.$this->quote($table->name).')');

            foreach ($info as $column) {
                $type = strtolower($column->type);

                if (str_starts_with($type, 'datetime') || str_starts_with($type, 'timestamp')) {
                    $columns[] = [$table->name, $column->name];
                }
            }
        }

        return $columns;
    }

    private function alreadyApplied(): bool
    {
        return Schema::hasTable('shared_timezone_backfill_log')
            && DB::table('shared_timezone_backfill_log')->where('direction', 'forward')->exists();
    }

    /**
     * How far the stored values are from UTC.
     *
     * Read at migration time from the zone the data was written in, so the
     * number that ran is derivable from configuration rather than folklore.
     */
    private function offsetSeconds(): int
    {
        $from = (string) config('shared.timezone.backfill_from', 'UTC');

        if ($from === '' || $from === 'UTC') {
            return 0;
        }

        // Lagos has no DST, so any reference instant gives the same answer; a
        // fixed one keeps this deterministic and testable.
        return (new DateTimeZone($from))->getOffset(new DateTimeImmutable('2026-01-01T00:00:00Z'));
    }

    private function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    private function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
};
