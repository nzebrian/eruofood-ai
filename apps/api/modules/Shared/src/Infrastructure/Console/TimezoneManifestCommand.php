<?php

declare(strict_types=1);

namespace EruoFood\Shared\Infrastructure\Console;

use EruoFood\Shared\Domain\Time\BackfillCategory;
use EruoFood\Shared\Domain\Time\ColumnClassification;
use EruoFood\Shared\Domain\Time\TimestampColumnClassifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Prints the UTC cutover classification for every temporal column.
 *
 * The migration decides in code what it will and will not touch; this is how a
 * person checks that decision *before* it runs against production, and audits
 * it afterwards. Run it against a restored copy of production and read the
 * excluded list: every column there is one whose historical meaning somebody
 * has to be able to defend.
 */
final class TimezoneManifestCommand extends Command
{
    protected $signature = 'shared:timezone-manifest {--excluded : Show only columns the cutover will not touch}';

    protected $description = 'Classify every temporal column for the UTC cutover, and show what will and will not be converted.';

    public function handle(): int
    {
        $classifier = new TimestampColumnClassifier();
        $classifications = [];

        foreach ($this->temporalColumns() as [$table, $column, $type]) {
            // A `date` or `interval` column is excluded by the discovery query
            // the migration uses, but it is shown here so the manifest covers
            // every temporal column in the schema rather than only the ones
            // that were ever candidates.
            $classifications[] = $type === 'timestamp without time zone'
                ? $classifier->classify($table, $column)
                : new ColumnClassification(
                    $table,
                    $column,
                    $type === 'date' ? BackfillCategory::DateOnly : BackfillCategory::Duration,
                    "SQL type `{$type}` is never a Lagos wall-clock instant; the migration does not discover it.",
                );
        }

        $byCategory = [];

        foreach ($classifications as $classification) {
            $byCategory[$classification->category->value][] = $classification;
        }

        ksort($byCategory);

        $this->line('');
        $this->line('<info>UTC cutover — column classification</info>');
        $this->line(sprintf('Total temporal columns: %d', count($classifications)));
        $this->line('');

        foreach ($byCategory as $category => $columns) {
            $enum = BackfillCategory::from((string) $category);

            $this->line(sprintf(
                '<comment>%s (%s) — %d column%s — %s</comment>',
                $enum->value,
                $enum->name,
                count($columns),
                count($columns) === 1 ? '' : 's',
                $enum->isConverted() ? 'CONVERTED' : 'NOT converted',
            ));
            $this->line('   '.$enum->explain());

            if ($enum->isConverted() && $this->option('excluded')) {
                $this->line('');

                continue;
            }

            foreach ($columns as $classification) {
                $this->line(sprintf('   %-56s %s', $classification->qualifiedName(), $classification->reason));
            }

            $this->line('');
        }

        return self::SUCCESS;
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    private function temporalColumns(): array
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->warn('Run this against PostgreSQL; SQLite does not report column types precisely enough to classify.');

            return [];
        }

        /** @var list<object{table_name: string, column_name: string, data_type: string}> $rows */
        $rows = DB::select(
            <<<'SQL'
            SELECT c.table_name, c.column_name, c.data_type
            FROM information_schema.columns c
            JOIN information_schema.tables t
              ON t.table_schema = c.table_schema AND t.table_name = c.table_name
            WHERE c.table_schema = current_schema()
              AND t.table_type = 'BASE TABLE'
              AND c.data_type IN (
                'timestamp without time zone', 'timestamp with time zone',
                'date', 'time without time zone', 'interval'
              )
            ORDER BY c.table_name, c.column_name
            SQL
        );

        return array_map(
            static fn (object $r): array => [$r->table_name, $r->column_name, $r->data_type],
            $rows,
        );
    }
}
