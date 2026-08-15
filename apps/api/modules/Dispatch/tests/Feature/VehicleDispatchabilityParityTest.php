<?php

declare(strict_types=1);

use EruoFood\Dispatch\Domain\Enum\VehicleType;
use EruoFood\Dispatch\Domain\Vehicle\VehicleRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * M26 — the SQL predicate and the aggregate must agree on "dispatchable".
 *
 * `EloquentVehicleRepository::dispatchable()` restates
 * {@see \EruoFood\Dispatch\Domain\Vehicle\Vehicle::isDispatchable()} in SQL.
 * That duplication is deliberate — candidate discovery filters a pool on every
 * dispatch, and hydrating the whole fleet to discard most of it would make the
 * dispatch engine the slowest thing in the request.
 *
 * Deliberate duplication still needs a guard. If the two drift, the symptom is
 * riders who are dispatchable by every rule anyone can read and are never
 * offered work — or worse, the reverse. So this walks the whole state space and
 * asserts the two answers match, case by case.
 */
function vehicleRow(array $overrides = []): string
{
    $id = (string) Str::uuid();

    DB::table('dispatch_vehicles')->insert(array_merge([
        'id' => $id,
        'rider_id' => (string) Str::uuid(),
        'type' => VehicleType::Bike->value,
        'status' => 'active',
        'verification_state' => 'verified',
        'verified_at' => now(),
        'verified_by' => (string) Str::uuid(),
        'is_primary' => false,
        'created_at' => now(),
        'updated_at' => now(),
        'version' => 1,
    ], $overrides));

    return $id;
}

it('agrees with the aggregate across every status and verification pairing', function (): void {
    $now = new DateTimeImmutable('2026-06-01 12:00:00');
    $repository = app(VehicleRepository::class);

    $statuses = ['pending_verification', 'active', 'suspended', 'retired'];
    $states = ['unverified', 'pending', 'verified', 'rejected', 'expired'];
    $expiries = [
        'no documents recorded' => null,
        'documents current' => new DateTimeImmutable('2026-12-01 00:00:00'),
        'documents lapsed' => new DateTimeImmutable('2026-01-01 00:00:00'),
    ];

    $checked = 0;

    foreach ($statuses as $status) {
        foreach ($states as $state) {
            // The database refuses an active-but-unverified vehicle outright,
            // which is itself part of the guarantee — skip the combinations it
            // will not store rather than pretending they are reachable.
            if ($status === 'active' && $state !== 'verified') {
                continue;
            }

            foreach ($expiries as $label => $expiry) {
                $riderId = (string) Str::uuid();
                $id = vehicleRow([
                    'rider_id' => $riderId,
                    'status' => $status,
                    'verification_state' => $state,
                    'verified_at' => $state === 'verified' ? now() : null,
                    'verified_by' => $state === 'verified' ? (string) Str::uuid() : null,
                    'insurance_expires_at' => $expiry,
                ]);

                $viaAggregate = $repository->find($id)?->isDispatchable($now);
                $viaSql = $repository->dispatchableFor($riderId, $now) !== [];

                expect($viaSql)->toBe(
                    $viaAggregate,
                    sprintf('status=%s state=%s expiry=%s', $status, $state, $label),
                );

                $checked++;
            }
        }
    }

    // A parity test that silently checked nothing would pass for ever.
    expect($checked)->toBe(48);
});

it('drops a vehicle from the query the moment its documents lapse', function (): void {
    $now = new DateTimeImmutable('2026-06-01 12:00:00');
    $repository = app(VehicleRepository::class);

    $riderId = (string) Str::uuid();
    vehicleRow(['rider_id' => $riderId, 'insurance_expires_at' => new DateTimeImmutable('2026-12-01')]);

    expect($repository->dispatchableFor($riderId, $now))->toHaveCount(1);

    // Move the clock past the expiry and the same row stops qualifying, with
    // nothing having been written.
    expect($repository->dispatchableFor($riderId, new DateTimeImmutable('2027-01-01 00:00:00')))
        ->toBeEmpty();
});

it('batches riders without changing the answer for any of them', function (): void {
    $now = new DateTimeImmutable('2026-06-01 12:00:00');
    $repository = app(VehicleRepository::class);

    $good = (string) Str::uuid();
    $lapsed = (string) Str::uuid();
    $unverified = (string) Str::uuid();

    vehicleRow(['rider_id' => $good]);
    vehicleRow(['rider_id' => $lapsed, 'insurance_expires_at' => new DateTimeImmutable('2026-01-01')]);
    vehicleRow([
        'rider_id' => $unverified,
        'status' => 'pending_verification',
        'verification_state' => 'unverified',
        'verified_at' => null,
        'verified_by' => null,
    ]);

    $batch = $repository->dispatchableForRiders([$good, $lapsed, $unverified], $now);

    expect(array_keys($batch))->toBe([$good])
        ->and($batch[$good])->toHaveCount(1);

    // The batch and the per-rider read must not disagree; a pool query that
    // quietly included an extra rider is how an unverified vehicle gets work.
    foreach ([$good, $lapsed, $unverified] as $riderId) {
        expect($batch[$riderId] ?? [])->toHaveCount(count($repository->dispatchableFor($riderId, $now)));
    }
});

it('returns nothing for an empty rider pool rather than everything', function (): void {
    vehicleRow();

    expect(app(VehicleRepository::class)->dispatchableForRiders([], new DateTimeImmutable()))->toBe([]);
});
