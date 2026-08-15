<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Interface\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The counts an operator reads before dispatch eligibility is switched on.
 *
 * M26 makes a verified vehicle a condition of receiving work. That is the right
 * rule and it has a consequence worth measuring before it takes effect: some
 * proportion of the existing fleet has no vehicle record that can be verified,
 * and on the day the switch flips those riders stop being offered deliveries.
 *
 * Nobody should discover that number from a support queue. This prints it, with
 * the reason attached, so the decision to enable `dispatch.engine.enabled` is
 * made with the size of the affected population in hand.
 *
 * Read-only. It changes nothing and can be run at any time.
 */
final class VehicleBackfillReportCommand extends Command
{
    protected $signature = 'dispatch:vehicle-backfill-report {--json : Emit machine-readable output}';

    protected $description = 'Report what the legacy vehicle backfill did, and who is left dispatch-ineligible.';

    public function handle(): int
    {
        if (! Schema::hasTable('dispatch_vehicle_backfill_log')) {
            $this->error('The vehicle backfill has not been migrated yet.');

            return self::FAILURE;
        }

        $byOutcome = $this->countsByOutcome();
        $riders = Schema::hasTable('marketplace_riders')
            ? DB::table('marketplace_riders')->count()
            : 0;

        $created = $byOutcome['vehicle_created'] ?? 0;
        $skipped = $byOutcome['skipped_already_has_vehicle'] ?? 0;
        $ineligible = array_sum($byOutcome) - $created - $skipped;

        $manualReview = (int) DB::table('dispatch_vehicle_backfill_log')
            ->where('needs_manual_review', true)
            ->count();

        // The reconciliation that makes this report trustworthy: a rider row
        // examined but never logged, or logged twice, would mean the backfill
        // missed part of the fleet. Reporting the two totals side by side is
        // what lets somebody notice.
        $examined = array_sum($byOutcome);

        $summary = [
            'riders_on_platform' => $riders,
            'riders_examined_by_backfill' => $examined,
            'riders_not_examined' => max(0, $riders - $examined),
            'vehicles_created' => $created,
            'riders_skipped_already_had_vehicle' => $skipped,
            'riders_dispatch_ineligible' => $ineligible,
            'rows_needing_manual_review' => $manualReview,
            'by_outcome' => $byOutcome,
            'ineligible_by_legacy_type' => $this->ineligibleByLegacyType(),
        ];

        if ($this->option('json') === true) {
            $this->line((string) json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('Legacy vehicle backfill');
        $this->newLine();

        $this->table(['Measure', 'Count'], [
            ['Riders on platform', $riders],
            ['Riders examined by backfill', $examined],
            ['Riders not examined', max(0, $riders - $examined)],
            ['Vehicles created (pending verification)', $created],
            ['Skipped — already had a vehicle', $skipped],
            ['Dispatch-ineligible until they register one', $ineligible],
            ['Rows needing manual review', $manualReview],
        ]);

        if ($byOutcome !== []) {
            $this->newLine();
            $this->line('By outcome:');
            $this->table(
                ['Outcome', 'Riders'],
                array_map(static fn (string $k, int $v): array => [$k, $v], array_keys($byOutcome), $byOutcome),
            );
        }

        $ineligibleByType = $this->ineligibleByLegacyType();

        if ($ineligibleByType !== []) {
            $this->newLine();
            $this->line('Dispatch-ineligible riders, by what their legacy record said:');
            $this->table(
                ['Legacy vehicle_type', 'Riders'],
                array_map(
                    static fn (string $k, int $v): array => [$k, $v],
                    array_keys($ineligibleByType),
                    $ineligibleByType,
                ),
            );
        }

        if ($ineligible > 0) {
            $this->newLine();
            $this->warn(sprintf(
                '%d rider(s) will stop receiving dispatch offers when dispatch.engine.enabled is turned on. '
                .'Their accounts, history and ratings are untouched — they need to register a supported '
                .'vehicle (bike, tricycle, car or bus) and have it verified.',
                $ineligible,
            ));
        }

        // No vehicle created by the backfill is usable until an operator
        // approves it, so the queue depth is the real gate on switching
        // dispatch on — not the backfill itself.
        if ($created > 0 && Schema::hasTable('dispatch_vehicles')) {
            $awaiting = (int) DB::table('dispatch_vehicles')
                ->whereIn('verification_state', ['unverified', 'pending'])
                ->where('status', '!=', 'retired')
                ->count();

            $this->newLine();
            $this->warn(sprintf(
                '%d vehicle(s) are awaiting verification. Backfilled vehicles are never auto-approved: '
                .'a value in the old vehicle_type column is not evidence that anybody saw the vehicle.',
                $awaiting,
            ));
        }

        return self::SUCCESS;
    }

    /** @return array<string, int> */
    private function countsByOutcome(): array
    {
        /** @var array<string, int> $counts */
        $counts = DB::table('dispatch_vehicle_backfill_log')
            ->selectRaw('outcome, COUNT(*) as total')
            ->groupBy('outcome')
            ->orderBy('outcome')
            ->pluck('total', 'outcome')
            ->map(static fn ($v): int => (int) $v)
            ->all();

        return $counts;
    }

    /** @return array<string, int> */
    private function ineligibleByLegacyType(): array
    {
        /** @var array<string, int> $counts */
        $counts = DB::table('dispatch_vehicle_backfill_log')
            ->whereNotIn('outcome', ['vehicle_created', 'skipped_already_has_vehicle'])
            ->selectRaw("COALESCE(legacy_vehicle_type, '(none recorded)') as legacy, COUNT(*) as total")
            ->groupBy('legacy')
            ->orderByDesc('total')
            ->pluck('total', 'legacy')
            ->map(static fn ($v): int => (int) $v)
            ->all();

        return $counts;
    }
}
