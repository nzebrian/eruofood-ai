<?php

declare(strict_types=1);

use EruoFood\Dispatch\Application\Service\VehicleService;
use EruoFood\Dispatch\Domain\Enum\VehicleStatus;
use EruoFood\Dispatch\Domain\Enum\VehicleType;
use EruoFood\Dispatch\Domain\Enum\VehicleVerificationState;
use EruoFood\Dispatch\Domain\Exception\DispatchNotAuthorized;
use EruoFood\Dispatch\Domain\Exception\DispatchNotFound;
use EruoFood\Dispatch\Domain\Exception\VehicleNotDispatchable;
use EruoFood\Dispatch\Domain\Vehicle\VehicleRepository;
use EruoFood\Shared\Domain\Clock;
use EruoFood\Shared\Domain\Exception\ConcurrencyConflict;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * M26 — vehicle registration and verification, end to end.
 *
 * Two properties carry the weight here.
 *
 * **A rider may describe their vehicle; only an operator may approve it.** If
 * any rider-reachable path produced a dispatchable vehicle, vehicle
 * verification would be a form riders fill in about themselves — and the
 * platform's answer to "is this rider insured?" would be "they said so".
 *
 * **A vehicle id in a URL proves nothing.** Ownership is checked against the
 * rider record on every rider-facing call, so holding somebody else's UUID does
 * not let you retire their motorbike.
 */

/** @return array{userId: string, riderId: string} */
function riderFor(string $vehicleType = 'motorbike'): array
{
    $userId = (string) Str::uuid();
    $riderId = (string) Str::uuid();

    DB::table('marketplace_riders')->insert([
        'id' => $riderId,
        'user_id' => $userId,
        'name' => 'Rider '.substr($riderId, 0, 4),
        'phone' => '+234800'.random_int(1_000_000, 9_999_999),
        'vehicle_type' => $vehicleType,
        'status' => 'offline',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return ['userId' => $userId, 'riderId' => $riderId];
}

/**
 * Operator ids are real UUIDs because `verified_by` is a uuid column.
 * SQLite would accept a label like 'operator-1'; PostgreSQL rejects it — the
 * same class of divergence that hid a production defect in M25.
 */
function anOperator(): string
{
    return (string) Str::uuid();
}

function vehicles(): VehicleService
{
    return app(VehicleService::class);
}

it('registers a rider vehicle as unusable until an operator approves it', function (): void {
    ['userId' => $userId] = riderFor();

    $vehicle = vehicles()->register($userId, VehicleType::Bike);

    expect($vehicle->status())->toBe(VehicleStatus::PendingVerification)
        ->and($vehicle->isDispatchable(new DateTimeImmutable()))->toBeFalse();

    // And it really is on disk in that state — not just in the returned object.
    expect(DB::table('dispatch_vehicles')->where('id', $vehicle->id())->value('status'))
        ->toBe('pending_verification');
});

it('makes a rider\'s first vehicle primary without asking', function (): void {
    ['userId' => $userId] = riderFor();

    $first = vehicles()->register($userId, VehicleType::Bike);
    $second = vehicles()->register($userId, VehicleType::Car, registrationNumber: 'LAG-1');

    expect($first->isPrimary())->toBeTrue()
        ->and($second->isPrimary())->toBeFalse();
});

it('caps how many vehicles one rider may register', function (): void {
    ['userId' => $userId] = riderFor();
    config()->set('dispatch.vehicles.max_per_rider', 2);
    app()->forgetInstance(VehicleService::class);

    vehicles()->register($userId, VehicleType::Bike);
    vehicles()->register($userId, VehicleType::Car, registrationNumber: 'LAG-1');

    expect(fn () => vehicles()->register($userId, VehicleType::Bus, registrationNumber: 'LAG-2'))
        ->toThrow(VehicleNotDispatchable::class, 'Retire one');
});

it('frees a slot when a vehicle is retired', function (): void {
    ['userId' => $userId] = riderFor();
    config()->set('dispatch.vehicles.max_per_rider', 1);
    app()->forgetInstance(VehicleService::class);

    $first = vehicles()->register($userId, VehicleType::Bike);
    vehicles()->retire($userId, $first->id());

    // A rider who replaced their bike should not be locked out of registering
    // the replacement.
    expect(vehicles()->register($userId, VehicleType::Car, registrationNumber: 'LAG-9')->id())
        ->not->toBe($first->id());
});

it('refuses to let a rider touch somebody else\'s vehicle', function (): void {
    ['userId' => $owner] = riderFor();
    ['userId' => $stranger] = riderFor();

    $vehicle = vehicles()->register($owner, VehicleType::Bike);

    expect(fn () => vehicles()->retire($stranger, $vehicle->id()))
        ->toThrow(DispatchNotAuthorized::class);

    expect(fn () => vehicles()->submitForVerification($stranger, $vehicle->id()))
        ->toThrow(DispatchNotAuthorized::class);

    expect(fn () => vehicles()->updateDocuments($stranger, $vehicle->id(), null, null, null))
        ->toThrow(DispatchNotAuthorized::class);

    expect(fn () => vehicles()->makePrimary($stranger, $vehicle->id()))
        ->toThrow(DispatchNotAuthorized::class);

    expect(fn () => vehicles()->getOwned($stranger, $vehicle->id()))
        ->toThrow(DispatchNotAuthorized::class);

    // The vehicle is untouched by all of that.
    expect(DB::table('dispatch_vehicles')->where('id', $vehicle->id())->value('status'))
        ->toBe('pending_verification');
});

it('shows a rider only their own vehicles', function (): void {
    ['userId' => $owner] = riderFor();
    ['userId' => $stranger] = riderFor();

    vehicles()->register($owner, VehicleType::Bike);
    vehicles()->register($stranger, VehicleType::Bike);

    expect(vehicles()->own($owner))->toHaveCount(1);
});

/**
 * The self-certification hole, tested through the service rather than the
 * aggregate: a rider extending their own insurance date must not keep a
 * dispatchable vehicle.
 */
it('sends a verified vehicle back to pending when its owner edits the documents', function (): void {
    ['userId' => $userId] = riderFor();
    $vehicle = vehicles()->register($userId, VehicleType::Bike);
    vehicles()->approve(anOperator(), $vehicle->id());

    $after = vehicles()->updateDocuments(
        $userId,
        $vehicle->id(),
        new DateTimeImmutable('+2 years'),
        null,
        null,
    );

    expect($after->verificationState())->toBe(VehicleVerificationState::Pending)
        ->and($after->isDispatchable(new DateTimeImmutable()))->toBeFalse();
});

it('refuses to approve a vehicle whose documents have already lapsed', function (): void {
    ['userId' => $userId] = riderFor();
    $vehicle = vehicles()->register($userId, VehicleType::Bike);
    vehicles()->updateDocuments($userId, $vehicle->id(), new DateTimeImmutable('-1 day'), null, null);

    // Approving would produce a vehicle that is verified and undispatchable at
    // the same instant — confusing to the rider and to the next operator.
    expect(fn () => vehicles()->approve(anOperator(), $vehicle->id()))
        ->toThrow(VehicleNotDispatchable::class, 'expired documents');
});

it('records who approved a vehicle, so no approval is unattributed', function (): void {
    ['userId' => $userId] = riderFor();
    $vehicle = vehicles()->register($userId, VehicleType::Bike);

    $operator = anOperator();
    $approved = vehicles()->approve($operator, $vehicle->id(), 'Papers seen in person.');

    expect($approved->verifiedBy())->toBe($operator)
        ->and($approved->verificationNote())->toBe('Papers seen in person.')
        ->and($approved->isDispatchable(new DateTimeImmutable()))->toBeTrue();
});

it('moves the primary flag rather than allowing two', function (): void {
    ['userId' => $userId, 'riderId' => $riderId] = riderFor();

    $first = vehicles()->register($userId, VehicleType::Bike);
    $second = vehicles()->register($userId, VehicleType::Car, registrationNumber: 'LAG-3');

    vehicles()->makePrimary($userId, $second->id());

    expect(DB::table('dispatch_vehicles')->where('rider_id', $riderId)->where('is_primary', true)->count())
        ->toBe(1)
        ->and(DB::table('dispatch_vehicles')->where('id', $second->id())->value('is_primary'))->toBeTruthy()
        ->and(DB::table('dispatch_vehicles')->where('id', $first->id())->value('is_primary'))->toBeFalsy();
});

it('refuses to make a retired vehicle primary', function (): void {
    ['userId' => $userId] = riderFor();
    $vehicle = vehicles()->register($userId, VehicleType::Bike);
    vehicles()->retire($userId, $vehicle->id());

    expect(fn () => vehicles()->makePrimary($userId, $vehicle->id()))
        ->toThrow(VehicleNotDispatchable::class, 'retired');
});

it('refuses a vehicle that does not exist without leaking whether it might', function (): void {
    ['userId' => $userId] = riderFor();

    expect(fn () => vehicles()->getOwned($userId, (string) Str::uuid()))
        ->toThrow(DispatchNotFound::class);
});

it('refuses to act for an account with no rider record', function (): void {
    expect(fn () => vehicles()->register((string) Str::uuid(), VehicleType::Bike))
        ->toThrow(DispatchNotFound::class);
});

/**
 * Two operators clicking Approve on the same vehicle at the same moment.
 *
 * The loser is told, rather than silently overwriting the winner. This is a
 * simulated race (the version is advanced underneath), not proof of database
 * locking — that comes from the real multi-process concurrency script, because
 * `RefreshDatabase` wraps each test in a transaction and so can never prove it.
 */
it('rejects a write made against a stale version', function (): void {
    ['userId' => $userId] = riderFor();
    $vehicle = vehicles()->register($userId, VehicleType::Bike);

    $stale = app(VehicleRepository::class)->find($vehicle->id());
    expect($stale)->not->toBeNull();

    // Somebody else commits first.
    vehicles()->approve(anOperator(), $vehicle->id());

    $stale->suspend('Stale write.', new DateTimeImmutable());

    expect(fn () => app(VehicleRepository::class)->save($stale))
        ->toThrow(ConcurrencyConflict::class);

    // And the winner's decision survived intact.
    expect(DB::table('dispatch_vehicles')->where('id', $vehicle->id())->value('status'))->toBe('active');
});

it('lists vehicles awaiting verification oldest first', function (): void {
    ['userId' => $a] = riderFor();
    ['userId' => $b] = riderFor();

    $first = vehicles()->register($a, VehicleType::Bike);
    $second = vehicles()->register($b, VehicleType::Bike);

    $queue = vehicles()->queue();

    expect($queue['total'])->toBe(2)
        ->and($queue['items'][0]->id())->toBe($first->id())
        ->and($queue['items'][1]->id())->toBe($second->id());
});

it('sweeps lapsed paperwork without that being what stops dispatch', function (): void {
    ['userId' => $userId] = riderFor();
    $vehicle = vehicles()->register($userId, VehicleType::Bike);
    vehicles()->updateDocuments($userId, $vehicle->id(), new DateTimeImmutable('+1 day'), null, null);
    vehicles()->approve(anOperator(), $vehicle->id());

    // Time passes past the expiry, with no sweep having run.
    $later = new DateTimeImmutable('+2 days');
    $loaded = app(VehicleRepository::class)->find($vehicle->id());

    expect($loaded->isDispatchable($later))->toBeFalse();

    // Only now does the sweep run, and all it does is relabel what was already
    // true. `SystemClock` reads the real clock, so the fake is how the future
    // is reached — travelling Carbon would not move it.
    app()->instance(Clock::class, new class ($later) implements Clock {
        public function __construct(private DateTimeImmutable $at)
        {
        }

        public function now(): DateTimeImmutable
        {
            return $this->at;
        }
    });
    app()->forgetInstance(VehicleService::class);

    expect(vehicles()->sweepExpired())->toBe(1);
    expect(app(VehicleRepository::class)->find($vehicle->id())->verificationState())
        ->toBe(VehicleVerificationState::Expired);
});

it('finds vehicles whose documents lapse soon', function (): void {
    ['userId' => $userId] = riderFor();
    $vehicle = vehicles()->register($userId, VehicleType::Bike);
    vehicles()->updateDocuments($userId, $vehicle->id(), new DateTimeImmutable('+7 days'), null, null);
    vehicles()->approve(anOperator(), $vehicle->id());

    expect(vehicles()->expiringSoon())->toHaveCount(1);
});
