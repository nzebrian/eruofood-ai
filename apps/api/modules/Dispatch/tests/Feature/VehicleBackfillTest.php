<?php

declare(strict_types=1);

use EruoFood\Dispatch\Domain\Enum\VehicleType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * M26 — the legacy vehicle backfill.
 *
 * This migration is the riskiest thing in M26, because it runs once against
 * every rider on the platform and its mistakes are not visible in a code
 * review. M26's approved decision set four conditions on it — **reversible,
 * counted, auditable, non-destructive** — and each has a test here.
 *
 * The consequence being guarded: making a verified vehicle a condition of
 * dispatch means some existing riders stop receiving work. That is correct.
 * What would not be correct is any of the tempting shortcuts — deleting them,
 * disabling their accounts, or guessing a vehicle type so the numbers look
 * better. The last is the worst: it would put a rider in the system on a
 * motorbike they do not own.
 */
function runBackfill(string $direction = 'up'): void
{
    $migration = require __DIR__.'/../../src/Infrastructure/Persistence/Migration/2027_07_01_000003_backfill_vehicles_from_marketplace_riders.php';

    expect($migration)->toBeInstanceOf(Migration::class);

    $direction === 'up' ? $migration->up() : $migration->down();
}

function legacyRider(string $vehicleType, ?string $id = null): string
{
    $id ??= (string) Str::uuid();

    DB::table('marketplace_riders')->insert([
        'id' => $id,
        'user_id' => (string) Str::uuid(),
        'name' => 'Rider '.substr($id, 0, 4),
        'phone' => '+234800'.random_int(1_000_000, 9_999_999),
        'vehicle_type' => $vehicleType,
        'status' => 'offline',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

it('creates a pending vehicle for every rider whose legacy type is supported', function (): void {
    $bicycle = legacyRider('bicycle');
    $motorbike = legacyRider('motorbike');
    $car = legacyRider('car');

    runBackfill();

    foreach ([$bicycle => 'bike', $motorbike => 'bike', $car => 'car'] as $riderId => $expectedType) {
        $vehicle = DB::table('dispatch_vehicles')->where('rider_id', $riderId)->first();

        expect($vehicle)->not->toBeNull()
            ->and($vehicle->type)->toBe($expectedType)
            // Never active. A string in a column is not evidence that anybody
            // ever saw the vehicle, let alone its insurance.
            ->and($vehicle->status)->toBe('pending_verification')
            ->and($vehicle->verification_state)->toBe('unverified')
            ->and($vehicle->verified_by)->toBeNull();
    }
});

/**
 * The decision that matters most, stated as a test.
 */
it('creates no vehicle for an on-foot rider and never invents a type', function (): void {
    $riderId = legacyRider('foot');

    runBackfill();

    expect(DB::table('dispatch_vehicles')->where('rider_id', $riderId)->exists())->toBeFalse();

    $log = DB::table('dispatch_vehicle_backfill_log')->where('rider_id', $riderId)->first();

    expect($log->outcome)->toBe('no_vehicle_unsupported_type')
        ->and($log->mapped_type)->toBeNull()
        ->and($log->needs_manual_review)->toBeTruthy()
        ->and($log->legacy_vehicle_type)->toBe('foot');
});

it('creates no vehicle for an unrecognised legacy value and flags it for review', function (): void {
    $riderId = legacyRider('hovercraft');

    runBackfill();

    expect(DB::table('dispatch_vehicles')->where('rider_id', $riderId)->exists())->toBeFalse();

    $log = DB::table('dispatch_vehicle_backfill_log')->where('rider_id', $riderId)->first();

    // Told apart from 'foot': one is a known situation, the other is a data
    // problem somebody should look at. Collapsing them would hide the typos.
    expect($log->outcome)->toBe('no_vehicle_unknown_type')
        ->and($log->mapped_type)->toBeNull()
        ->and($log->needs_manual_review)->toBeTruthy();
});

/**
 * Non-destructive: the whole point of the "no vehicle" outcome is that the
 * rider is still there.
 */
it('leaves every rider record exactly as it found it', function (): void {
    $riders = [
        legacyRider('foot'),
        legacyRider('motorbike'),
        legacyRider('hovercraft'),
    ];

    $before = DB::table('marketplace_riders')->orderBy('id')->get()->toArray();

    runBackfill();

    $after = DB::table('marketplace_riders')->orderBy('id')->get()->toArray();

    expect($after)->toEqual($before)
        ->and(DB::table('marketplace_riders')->count())->toBe(count($riders));

    // The legacy column stays readable through the transition, deprecated but
    // intact, so the backfill can be re-derived and nothing still reading it
    // breaks.
    expect(DB::table('marketplace_riders')->whereNull('vehicle_type')->count())->toBe(0);
});

it('writes one log row per rider examined, including the ones it created nothing for', function (): void {
    legacyRider('bicycle');
    legacyRider('foot');
    legacyRider('hovercraft');
    legacyRider('');

    runBackfill();

    expect(DB::table('dispatch_vehicle_backfill_log')->count())->toBe(4)
        ->and(DB::table('dispatch_vehicles')->count())->toBe(1);

    $outcomes = DB::table('dispatch_vehicle_backfill_log')
        ->pluck('outcome')
        ->sort()
        ->values()
        ->all();

    expect($outcomes)->toBe([
        'no_vehicle_type_recorded',
        'no_vehicle_unknown_type',
        'no_vehicle_unsupported_type',
        'vehicle_created',
    ]);
});

it('rolls back what it created', function (): void {
    legacyRider('bicycle');
    legacyRider('car');
    legacyRider('foot');

    runBackfill();
    expect(DB::table('dispatch_vehicles')->count())->toBe(2);

    runBackfill('down');

    expect(DB::table('dispatch_vehicles')->count())->toBe(0)
        ->and(DB::table('dispatch_vehicle_backfill_log')->count())->toBe(0)
        // Rolling back the vehicle backfill must not touch riders either.
        ->and(DB::table('marketplace_riders')->count())->toBe(3);
});

/**
 * The part of "reversible" that is easy to get wrong.
 *
 * A rollback that deleted every backfilled vehicle would destroy a morning of
 * operator verification work to undo a schema change — more damage than the
 * change it is reverting. So rollback skips anything a human has since touched.
 */
it('refuses to delete a backfilled vehicle an operator has since verified', function (): void {
    $riderId = legacyRider('motorbike');
    runBackfill();

    $vehicleId = (string) DB::table('dispatch_vehicles')->where('rider_id', $riderId)->value('id');

    DB::table('dispatch_vehicles')->where('id', $vehicleId)->update([
        'status' => 'active',
        'verification_state' => 'verified',
        'verified_at' => now(),
        'verified_by' => (string) Str::uuid(),
        'version' => 2,
    ]);

    runBackfill('down');

    expect(DB::table('dispatch_vehicles')->where('id', $vehicleId)->exists())->toBeTrue();
});

it('does not double-register a rider who already has a vehicle', function (): void {
    $riderId = legacyRider('bicycle');

    runBackfill();
    expect(DB::table('dispatch_vehicles')->where('rider_id', $riderId)->count())->toBe(1);

    // Re-running — an interrupted deploy, a replayed migration.
    runBackfill();

    expect(DB::table('dispatch_vehicles')->where('rider_id', $riderId)->count())->toBe(1)
        ->and(
            DB::table('dispatch_vehicle_backfill_log')
                ->where('rider_id', $riderId)
                ->where('outcome', 'skipped_already_has_vehicle')
                ->exists(),
        )->toBeTrue();
});

it('makes a rider\'s only backfilled vehicle their primary one', function (): void {
    $riderId = legacyRider('motorbike');

    runBackfill();

    expect(DB::table('dispatch_vehicles')->where('rider_id', $riderId)->value('is_primary'))->toBeTruthy();
});

it('flags a backfilled car as needing a plate before it can be approved', function (): void {
    $bikeRider = legacyRider('bicycle');
    $carRider = legacyRider('car');

    runBackfill();

    // The legacy record never held a registration number, so a car created
    // from it cannot pass verification until the rider supplies one. Saying so
    // is the difference between an operator queue and a mystery.
    expect(DB::table('dispatch_vehicle_backfill_log')->where('rider_id', $carRider)->value('needs_manual_review'))
        ->toBeTruthy();

    expect(DB::table('dispatch_vehicle_backfill_log')->where('rider_id', $bikeRider)->value('needs_manual_review'))
        ->toBeFalsy();
});

/**
 * The duplication guard.
 *
 * The migration hard-codes its own mapping table rather than calling
 * `VehicleType::fromLegacy()`, because a migration is a historical record and
 * must keep meaning what it meant on the day it ran. That is only safe while
 * something checks the two still agree.
 */
it('maps legacy values identically to the domain enum', function (): void {
    $reflection = new ReflectionClass(
        require __DIR__.'/../../src/Infrastructure/Persistence/Migration/2027_07_01_000003_backfill_vehicles_from_marketplace_riders.php',
    );

    /** @var array<string, string> $map */
    $map = $reflection->getConstant('MAP');

    expect($map)->not->toBeEmpty();

    foreach ($map as $legacy => $expected) {
        expect(VehicleType::fromLegacy($legacy)?->value)->toBe(
            $expected,
            sprintf('Migration maps "%s" to "%s"; the enum disagrees.', $legacy, $expected),
        );
    }

    // And the other direction: every legacy string the enum recognises must be
    // one the migration also handled, or the backfill silently skipped riders
    // the platform considers mappable.
    foreach (['bicycle', 'motorbike', 'bike', 'motorcycle', 'tricycle', 'keke', 'keke napep', 'car', 'sedan', 'bus', 'van'] as $legacy) {
        if (VehicleType::fromLegacy($legacy) !== null) {
            expect($map)->toHaveKey($legacy);
        }
    }
});

it('reports counts that reconcile against the rider table', function (): void {
    legacyRider('bicycle');
    legacyRider('motorbike');
    legacyRider('foot');
    legacyRider('hovercraft');

    runBackfill();

    $this->artisan('dispatch:vehicle-backfill-report --json')
        ->assertExitCode(0);

    $examined = DB::table('dispatch_vehicle_backfill_log')->count();
    $riders = DB::table('marketplace_riders')->count();

    // If these ever diverge, part of the fleet was never examined — the single
    // most important thing this report exists to surface.
    expect($examined)->toBe($riders);
});
