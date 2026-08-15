<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * M26 — the guarantees that survive a bug in the application layer.
 *
 * Every rule here is also enforced by the aggregate. That is not duplication
 * for its own sake: the service layer is one refactor, one new endpoint or one
 * raw query away from being bypassed, and the rules below are the ones where
 * being bypassed means an unverified vehicle receiving work or two riders
 * claiming one plate.
 *
 * M25 is why several of these run against PostgreSQL specifically: a column
 * that was silently truncated by SQLite and rejected by PostgreSQL would have
 * meant a 100% cache miss in production and nowhere else. SQLite is the fast
 * path, not the guarantee.
 */
function rawVehicle(array $overrides = []): array
{
    return array_merge([
        'id' => (string) Str::uuid(),
        'rider_id' => (string) Str::uuid(),
        'type' => 'bike',
        'status' => 'pending_verification',
        'verification_state' => 'unverified',
        'is_primary' => false,
        'created_at' => now(),
        'updated_at' => now(),
        'version' => 1,
    ], $overrides);
}

function insertRawVehicle(array $overrides = []): string
{
    $row = rawVehicle($overrides);
    DB::table('dispatch_vehicles')->insert($row);

    return (string) $row['id'];
}

describe('checks enforced by PostgreSQL', function (): void {
    /**
     * The single most consequential rule in the table: a vehicle cannot be in
     * service unless somebody verified it.
     */
    it('refuses an active vehicle nobody verified', function (): void {
        expect(fn () => insertRawVehicle([
            'status' => 'active',
            'verification_state' => 'unverified',
        ]))->toThrow(QueryException::class, 'dispatch_vehicles_active_requires_verified');
    });

    it('refuses a verified vehicle with no verifier recorded', function (): void {
        // Without both the operator and the timestamp, "verified" is an
        // assertion nobody signed.
        expect(fn () => insertRawVehicle([
            'status' => 'active',
            'verification_state' => 'verified',
        ]))->toThrow(QueryException::class, 'dispatch_vehicles_verified_is_attributed');
    });

    it('refuses a vehicle type the enum does not have', function (): void {
        // Catches a deploy that adds a fifth type without a migration, rather
        // than letting dispatch silently match nothing.
        expect(fn () => insertRawVehicle(['type' => 'hovercraft']))
            ->toThrow(QueryException::class, 'dispatch_vehicles_type_known');
    });

    /*
     * One violation per test, deliberately.
     *
     * PostgreSQL aborts the surrounding transaction on a constraint violation,
     * so a second failing statement in the same test reports "current
     * transaction is aborted" instead of the constraint that actually fired —
     * which would make the second assertion pass or fail for the wrong reason.
     */
    it('refuses a status the enum does not have', function (): void {
        expect(fn () => insertRawVehicle(['status' => 'probationary']))
            ->toThrow(QueryException::class, 'dispatch_vehicles_status_known');
    });

    it('refuses a verification state the enum does not have', function (): void {
        expect(fn () => insertRawVehicle(['verification_state' => 'maybe']))
            ->toThrow(QueryException::class, 'dispatch_vehicles_verification_state_known');
    });

    it('refuses a zero or negative capacity', function (): void {
        expect(fn () => insertRawVehicle(['capacity_kg' => 0]))
            ->toThrow(QueryException::class, 'dispatch_vehicles_capacity_positive');
    });
})->skip(
    fn (): bool => DB::connection()->getDriverName() !== 'pgsql',
    'CHECK constraints are declared for PostgreSQL; SQLite is the fast path, not the guarantee.',
);

/**
 * Partial unique indexes work the same way in SQLite and PostgreSQL, so these
 * run everywhere — the fast test path exercises the real guarantee rather than
 * a weakened stand-in that would let a bug through locally.
 */
it('refuses two live vehicles claiming the same registration number', function (): void {
    insertRawVehicle(['registration_number' => 'LAG-777-XY']);

    // A plate identifies a vehicle to the state. Two riders holding one is
    // either a typo or somebody passing verification on a colleague's papers.
    expect(fn () => insertRawVehicle(['registration_number' => 'LAG-777-XY']))
        ->toThrow(QueryException::class);
});

it('frees a registration number once the vehicle is retired', function (): void {
    insertRawVehicle(['registration_number' => 'LAG-888-XY', 'status' => 'retired']);

    // Selling a motorbike must not poison its plate for the next owner.
    $second = insertRawVehicle(['registration_number' => 'LAG-888-XY']);

    expect(DB::table('dispatch_vehicles')->where('id', $second)->exists())->toBeTrue();
});

it('refuses a second primary vehicle for one rider', function (): void {
    $riderId = (string) Str::uuid();
    insertRawVehicle(['rider_id' => $riderId, 'is_primary' => true]);

    expect(fn () => insertRawVehicle(['rider_id' => $riderId, 'is_primary' => true]))
        ->toThrow(QueryException::class);
});

it('lets a rider replace a retired primary vehicle', function (): void {
    $riderId = (string) Str::uuid();
    insertRawVehicle(['rider_id' => $riderId, 'is_primary' => true, 'status' => 'retired']);

    $replacement = insertRawVehicle(['rider_id' => $riderId, 'is_primary' => true]);

    expect(DB::table('dispatch_vehicles')->where('id', $replacement)->value('is_primary'))->toBeTruthy();
});

it('allows many non-primary vehicles, which is the point of the partial index', function (): void {
    $riderId = (string) Str::uuid();
    insertRawVehicle(['rider_id' => $riderId, 'is_primary' => true]);
    insertRawVehicle(['rider_id' => $riderId]);
    insertRawVehicle(['rider_id' => $riderId]);

    expect(DB::table('dispatch_vehicles')->where('rider_id', $riderId)->count())->toBe(3);
});
