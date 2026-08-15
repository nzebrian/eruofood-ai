<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Give existing riders a vehicle record, without inventing anything.
 *
 * Every rider on the platform today has a `marketplace_riders.vehicle_type`
 * string and nothing else. M26 makes dispatch eligibility depend on a verified
 * vehicle, so leaving that string behind would make the entire existing fleet
 * ineligible overnight with no path back. Backfilling it blindly would be
 * worse: it would manufacture verified-looking records for vehicles nobody has
 * ever seen.
 *
 * The line this migration walks:
 *
 * - Where the legacy string names a supported vehicle, create the vehicle —
 *   **pending verification, never active**. A string in a column is not
 *   evidence of insurance, and the whole point of vehicle verification is that
 *   somebody looks. These riders keep working the moment an operator approves
 *   them, with no re-registration.
 * - Where it does not (`foot`, blanks, anything unrecognised), create nothing
 *   and say so. Those riders keep their accounts, their history and their
 *   ratings; they are simply dispatch-ineligible until they register a real
 *   vehicle. Guessing a type for them would be the one genuinely unsafe option
 *   — it would put someone on a motorbike in the system who is not on one.
 *
 * Nothing here deletes, disables or rewrites a rider row. `vehicle_type` is
 * left exactly as it was: it stays readable through the transition so this
 * migration can be re-derived, and so nothing that still reads it breaks.
 *
 * Counts land in `dispatch_vehicle_backfill_log` and in the application log.
 * See the `dispatch:vehicle-backfill-report` command for the summary an
 * operator reads before dispatch eligibility is switched on.
 */
return new class () extends Migration {
    /**
     * The legacy string → supported type map.
     *
     * Deliberately written out here rather than calling
     * `VehicleType::fromLegacy()`. A migration is a historical record: it must
     * keep meaning what it meant on the day it ran, even after the enum grows
     * a case. `VehicleBackfillTest` asserts the two agree today, in both
     * directions, so the duplication cannot drift unnoticed while they are
     * meant to match.
     *
     * Absent key = no supported vehicle. There is no default branch, on
     * purpose.
     *
     * @var array<string, string>
     */
    private const MAP = [
        'bicycle' => 'bike',
        'motorbike' => 'bike',
        'bike' => 'bike',
        'motorcycle' => 'bike',
        'tricycle' => 'tricycle',
        'keke' => 'tricycle',
        'keke napep' => 'tricycle',
        'car' => 'car',
        'sedan' => 'car',
        'bus' => 'bus',
        'van' => 'bus',
    ];

    /** Types that cannot be verified without a plate we do not have on record. */
    private const NEEDS_REGISTRATION = ['tricycle', 'car', 'bus'];

    private const OUTCOME_CREATED = 'vehicle_created';
    private const OUTCOME_UNSUPPORTED = 'no_vehicle_unsupported_type';
    private const OUTCOME_UNKNOWN = 'no_vehicle_unknown_type';
    private const OUTCOME_MISSING = 'no_vehicle_type_recorded';
    private const OUTCOME_SKIPPED = 'skipped_already_has_vehicle';

    public function up(): void
    {
        if (! Schema::hasTable('marketplace_riders')) {
            return;
        }

        $now = now();
        $counts = [];

        // Chunked so this stays bounded on a fleet of any size — a backfill
        // that loads every rider into memory is a migration that works in
        // staging and takes the site down in production.
        DB::table('marketplace_riders')
            ->select(['id', 'vehicle_type'])
            ->orderBy('id')
            ->chunk(500, function ($riders) use ($now, &$counts): void {
                $rows = [];
                $logs = [];

                foreach ($riders as $rider) {
                    $decision = $this->decide((string) $rider->id, $rider->vehicle_type, $now);

                    if ($decision['vehicle'] !== null) {
                        $rows[] = $decision['vehicle'];
                    }

                    $logs[] = $decision['log'];
                    $outcome = (string) $decision['log']['outcome'];
                    $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
                }

                if ($rows !== []) {
                    DB::table('dispatch_vehicles')->insert($rows);
                }

                if ($logs !== []) {
                    DB::table('dispatch_vehicle_backfill_log')->insert($logs);
                }
            });

        $this->recordSummary($counts);
    }

    /**
     * Undo only what this migration did, and only where it is still untouched.
     *
     * Rollback consults the log rather than deleting every vehicle, and it
     * skips any vehicle a human has since acted on — one an operator verified,
     * or a rider corrected. Those are real records now, whatever created them,
     * and a rollback that destroyed a morning of verification work to undo a
     * schema change would be doing more damage than the change it is reverting.
     *
     * Anything skipped is reported rather than silently left behind.
     */
    public function down(): void
    {
        if (! Schema::hasTable('dispatch_vehicle_backfill_log') || ! Schema::hasTable('dispatch_vehicles')) {
            return;
        }

        /** @var list<string> $created */
        $created = DB::table('dispatch_vehicle_backfill_log')
            ->where('outcome', self::OUTCOME_CREATED)
            ->whereNotNull('vehicle_id')
            ->pluck('vehicle_id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        $removed = 0;
        $retained = 0;

        foreach (array_chunk($created, 500) as $chunk) {
            $removed += DB::table('dispatch_vehicles')
                ->whereIn('id', $chunk)
                // Untouched since the backfill wrote it: nobody has verified,
                // suspended, edited or re-registered this vehicle.
                ->where('version', 1)
                ->where('verification_state', 'unverified')
                ->where('status', 'pending_verification')
                ->delete();

            $retained += DB::table('dispatch_vehicles')->whereIn('id', $chunk)->count();
        }

        DB::table('dispatch_vehicle_backfill_log')->delete();

        Log::info('dispatch.vehicle_backfill.rolled_back', [
            'vehicles_created_by_backfill' => count($created),
            'vehicles_removed' => $removed,
            'vehicles_retained_because_modified' => $retained,
        ]);
    }

    /**
     * One rider's outcome: the vehicle row to insert (or none) and the log row.
     *
     * @return array{vehicle: array<string, mixed>|null, log: array<string, mixed>}
     */
    private function decide(string $riderId, mixed $legacyType, mixed $now): array
    {
        $log = [
            'id' => (string) Str::uuid(),
            'rider_id' => $riderId,
            'legacy_vehicle_type' => is_string($legacyType) ? $legacyType : null,
            'mapped_type' => null,
            'outcome' => self::OUTCOME_MISSING,
            'needs_manual_review' => false,
            'vehicle_id' => null,
            'note' => null,
            'created_at' => $now,
        ];

        // Re-running must not double-register. A rider who already has a
        // vehicle got one through the API, and that record outranks anything
        // inferable from the legacy string.
        if (DB::table('dispatch_vehicles')->where('rider_id', $riderId)->exists()) {
            $log['outcome'] = self::OUTCOME_SKIPPED;
            $log['note'] = 'Rider already has a vehicle record; legacy value not applied.';

            return ['vehicle' => null, 'log' => $log];
        }

        if (! is_string($legacyType) || trim($legacyType) === '') {
            $log['needs_manual_review'] = true;
            $log['note'] = 'No legacy vehicle type recorded. Rider stays active but dispatch-ineligible.';

            return ['vehicle' => null, 'log' => $log];
        }

        $normalised = mb_strtolower(trim($legacyType));
        $mapped = self::MAP[$normalised] ?? null;

        if ($mapped === null) {
            // 'foot' is a known value with no supported vehicle — a different
            // situation from a typo, and worth telling apart in the report.
            $isKnownUnsupported = $normalised === 'foot';

            $log['outcome'] = $isKnownUnsupported ? self::OUTCOME_UNSUPPORTED : self::OUTCOME_UNKNOWN;
            $log['needs_manual_review'] = true;
            $log['note'] = $isKnownUnsupported
                ? 'On-foot riders have no supported vehicle. Account stays active; dispatch-ineligible until a vehicle is registered.'
                : 'Unrecognised legacy vehicle type. No type inferred; needs manual review.';

            return ['vehicle' => null, 'log' => $log];
        }

        $vehicleId = (string) Str::uuid();
        $needsPlate = in_array($mapped, self::NEEDS_REGISTRATION, true);

        $log['mapped_type'] = $mapped;
        $log['outcome'] = self::OUTCOME_CREATED;
        $log['vehicle_id'] = $vehicleId;
        $log['needs_manual_review'] = $needsPlate;
        $log['note'] = $needsPlate
            ? 'Created pending verification. No registration number on legacy record; the rider must supply one before approval.'
            : 'Created pending verification from the legacy vehicle type.';

        return [
            'vehicle' => [
                'id' => $vehicleId,
                'rider_id' => $riderId,
                'type' => $mapped,
                'registration_number' => null,
                'make' => null,
                'model' => null,
                'colour' => null,
                'capacity_kg' => null,
                'capacity_litres' => null,
                // Never 'active'. The legacy column recorded what a rider once
                // typed, not what anyone checked.
                'status' => 'pending_verification',
                'verification_state' => 'unverified',
                'verified_at' => null,
                'verified_by' => null,
                'verification_note' => null,
                'insurance_expires_at' => null,
                'roadworthiness_expires_at' => null,
                'licence_expires_at' => null,
                // The rider's only vehicle, so it is their primary one. The
                // partial unique index tolerates this because the skip above
                // guarantees they had none.
                'is_primary' => true,
                'created_at' => $now,
                'updated_at' => $now,
                'version' => 1,
            ],
            'log' => $log,
        ];
    }

    /** @param  array<string, int>  $counts */
    private function recordSummary(array $counts): void
    {
        $created = $counts[self::OUTCOME_CREATED] ?? 0;
        $ineligible = ($counts[self::OUTCOME_UNSUPPORTED] ?? 0)
            + ($counts[self::OUTCOME_UNKNOWN] ?? 0)
            + ($counts[self::OUTCOME_MISSING] ?? 0);

        Log::info('dispatch.vehicle_backfill.completed', [
            'riders_examined' => array_sum($counts),
            'vehicles_created' => $created,
            'riders_left_dispatch_ineligible' => $ineligible,
            'riders_skipped_existing_vehicle' => $counts[self::OUTCOME_SKIPPED] ?? 0,
            'by_outcome' => $counts,
        ]);
    }
};
