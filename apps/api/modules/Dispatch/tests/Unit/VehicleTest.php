<?php

declare(strict_types=1);

use EruoFood\Dispatch\Domain\Enum\VehicleStatus;
use EruoFood\Dispatch\Domain\Enum\VehicleType;
use EruoFood\Dispatch\Domain\Enum\VehicleVerificationState;
use EruoFood\Dispatch\Domain\Exception\VehicleNotDispatchable;
use EruoFood\Dispatch\Domain\Vehicle\Vehicle;

/**
 * M26 — the vehicle aggregate.
 *
 * The single rule under test throughout: **a rider may describe their vehicle,
 * only an operator may approve it.** Every path a rider can reach either leaves
 * the vehicle unusable or pushes it back to pending. If any of these fail,
 * vehicle verification has become a form riders fill in about themselves.
 */
function aVehicle(
    VehicleType $type = VehicleType::Bike,
    ?DateTimeImmutable $now = null,
    ?string $registration = null,
): Vehicle {
    return Vehicle::register(
        id: '11111111-1111-4111-8111-111111111111',
        riderId: '22222222-2222-4222-8222-222222222222',
        type: $type,
        now: $now ?? new DateTimeImmutable('2026-01-01 09:00:00'),
        registrationNumber: $registration,
    );
}

it('registers as pending verification, never active', function (): void {
    $vehicle = aVehicle();

    expect($vehicle->status())->toBe(VehicleStatus::PendingVerification)
        ->and($vehicle->verificationState())->toBe(VehicleVerificationState::Unverified)
        ->and($vehicle->isDispatchable(new DateTimeImmutable()))->toBeFalse();
});

it('refuses to register a plated vehicle without a plate', function (): void {
    expect(fn (): Vehicle => aVehicle(VehicleType::Car))
        ->toThrow(VehicleNotDispatchable::class, 'registration number');
});

it('accepts a bike with no plate, because many genuinely have none', function (): void {
    expect(aVehicle(VehicleType::Bike)->registrationNumber())->toBeNull();
});

it('normalises a registration number so two riders cannot hold the same plate in different cases', function (): void {
    expect(aVehicle(VehicleType::Car, registration: '  lag-123-ab ')->registrationNumber())
        ->toBe('LAG-123-AB');
});

it('becomes dispatchable only once an operator approves it', function (): void {
    $now = new DateTimeImmutable('2026-01-01 10:00:00');
    $vehicle = aVehicle();

    expect($vehicle->isDispatchable($now))->toBeFalse();

    $vehicle->approve('operator-1', $now);

    expect($vehicle->isDispatchable($now))->toBeTrue()
        ->and($vehicle->verifiedBy())->toBe('operator-1')
        ->and($vehicle->verifiedAt())->toEqual($now);
});

/**
 * The clock, not a flag.
 *
 * If dispatchability were a status somebody had to flip, an uninsured vehicle
 * would keep receiving work until whenever the nightly job next ran — or
 * indefinitely, if it failed.
 */
it('stops being dispatchable the moment a document lapses, with no sweep required', function (): void {
    $approvedAt = new DateTimeImmutable('2026-01-01 10:00:00');
    $vehicle = aVehicle();
    $vehicle->approve('operator-1', $approvedAt);
    $vehicle->updateDocuments(
        insuranceExpiresAt: new DateTimeImmutable('2026-02-01 00:00:00'),
        roadworthinessExpiresAt: null,
        licenceExpiresAt: null,
        now: $approvedAt,
    );
    $vehicle->approve('operator-1', $approvedAt);

    expect($vehicle->isDispatchable(new DateTimeImmutable('2026-01-31 23:59:00')))->toBeTrue()
        // One minute past the expiry, with nothing else having happened.
        ->and($vehicle->isDispatchable(new DateTimeImmutable('2026-02-01 00:01:00')))->toBeFalse()
        // Still 'verified' — the paperwork was genuine, it simply ran out.
        ->and($vehicle->verificationState())->toBe(VehicleVerificationState::Verified);
});

it('treats an unrecorded document as unrecorded, not as expired', function (): void {
    $now = new DateTimeImmutable('2026-01-01 10:00:00');
    $vehicle = aVehicle();
    $vehicle->approve('operator-1', $now);

    expect($vehicle->documentsAreCurrent($now))->toBeTrue()
        ->and($vehicle->isDispatchable($now))->toBeTrue();
});

/**
 * The self-certification hole this closes: without it, a rider could get a
 * vehicle verified once and then edit the insurance date forward for ever.
 */
it('sends a verified vehicle back to pending when its documents change', function (): void {
    $now = new DateTimeImmutable('2026-01-01 10:00:00');
    $vehicle = aVehicle();
    $vehicle->approve('operator-1', $now);
    expect($vehicle->isDispatchable($now))->toBeTrue();

    $vehicle->updateDocuments(
        insuranceExpiresAt: new DateTimeImmutable('2027-01-01 00:00:00'),
        roadworthinessExpiresAt: null,
        licenceExpiresAt: null,
        now: $now,
    );

    expect($vehicle->verificationState())->toBe(VehicleVerificationState::Pending)
        ->and($vehicle->status())->toBe(VehicleStatus::PendingVerification)
        ->and($vehicle->verifiedAt())->toBeNull()
        ->and($vehicle->isDispatchable($now))->toBeFalse();
});

it('does not disturb an unverified vehicle when its documents change', function (): void {
    $now = new DateTimeImmutable('2026-01-01 10:00:00');
    $vehicle = aVehicle();

    $vehicle->updateDocuments(new DateTimeImmutable('2027-01-01'), null, null, $now);

    expect($vehicle->verificationState())->toBe(VehicleVerificationState::Unverified);
});

it('warns before documents lapse rather than after', function (): void {
    $now = new DateTimeImmutable('2026-01-01 10:00:00');
    $vehicle = aVehicle();
    $vehicle->updateDocuments(new DateTimeImmutable('2026-01-10 10:00:00'), null, null, $now);

    expect($vehicle->expiresWithin($now, 14))->toBeTrue()
        ->and($vehicle->expiresWithin($now, 5))->toBeFalse();
});

it('does not warn about a document that has already lapsed', function (): void {
    // Already gone is a different message and a different queue; conflating
    // the two would tell a rider to renew something they cannot still renew.
    $now = new DateTimeImmutable('2026-01-01 10:00:00');
    $vehicle = aVehicle();
    $vehicle->updateDocuments(new DateTimeImmutable('2025-12-01 10:00:00'), null, null, $now);

    expect($vehicle->expiresWithin($now, 14))->toBeFalse();
});

it('suspends and reinstates without losing the verification decision', function (): void {
    $now = new DateTimeImmutable('2026-01-01 10:00:00');
    $vehicle = aVehicle();
    $vehicle->approve('operator-1', $now);

    $vehicle->suspend('Failed a spot inspection.', $now);
    expect($vehicle->isDispatchable($now))->toBeFalse()
        ->and($vehicle->verificationState())->toBe(VehicleVerificationState::Verified);

    $vehicle->reinstate($now);
    expect($vehicle->isDispatchable($now))->toBeTrue();
});

it('refuses to reinstate a vehicle nobody ever verified', function (): void {
    $vehicle = aVehicle();
    $vehicle->suspend('Reported stolen.', new DateTimeImmutable());

    expect(fn () => $vehicle->reinstate(new DateTimeImmutable()))
        ->toThrow(VehicleNotDispatchable::class, 'unverified');
});

it('refuses to resubmit a retired vehicle', function (): void {
    $vehicle = aVehicle();
    $vehicle->retire(new DateTimeImmutable());

    expect(fn () => $vehicle->submitForVerification(new DateTimeImmutable()))
        ->toThrow(VehicleNotDispatchable::class, 'retired');
});

it('clears the primary flag on retirement so the slot frees up', function (): void {
    $vehicle = Vehicle::register(
        id: '11111111-1111-4111-8111-111111111111',
        riderId: '22222222-2222-4222-8222-222222222222',
        type: VehicleType::Bike,
        now: new DateTimeImmutable(),
        isPrimary: true,
    );

    $vehicle->retire(new DateTimeImmutable());

    expect($vehicle->isPrimary())->toBeFalse()
        ->and($vehicle->status())->toBe(VehicleStatus::Retired);
});

it('marks lapsed paperwork as expired for reporting', function (): void {
    $approvedAt = new DateTimeImmutable('2026-01-01 10:00:00');
    $vehicle = aVehicle();
    $vehicle->updateDocuments(new DateTimeImmutable('2026-02-01 00:00:00'), null, null, $approvedAt);
    $vehicle->approve('operator-1', $approvedAt);

    $vehicle->markExpired(new DateTimeImmutable('2026-03-01 00:00:00'));

    expect($vehicle->verificationState())->toBe(VehicleVerificationState::Expired)
        ->and($vehicle->verificationState()->isResubmittable())->toBeTrue()
        // And off Active with it. Leaving the status behind would show an
        // in-service vehicle in every operator list that dispatch silently
        // refuses — and PostgreSQL will not store the pair.
        ->and($vehicle->status())->toBe(VehicleStatus::PendingVerification);
});

it('answers suitability from both type and capacity', function (): void {
    $car = Vehicle::register(
        id: '11111111-1111-4111-8111-111111111111',
        riderId: '22222222-2222-4222-8222-222222222222',
        type: VehicleType::Car,
        now: new DateTimeImmutable(),
        registrationNumber: 'LAG-1',
        capacityKg: 50,
    );

    expect($car->satisfies(VehicleType::Bike, 30))->toBeTrue()
        ->and($car->satisfies(VehicleType::Bike, 80))->toBeFalse()
        ->and($car->satisfies(VehicleType::Bus, 10))->toBeFalse();
});

it('answers ownership by rider, which is what the IDOR check leans on', function (): void {
    $vehicle = aVehicle();

    expect($vehicle->belongsTo('22222222-2222-4222-8222-222222222222'))->toBeTrue()
        ->and($vehicle->belongsTo('33333333-3333-4333-8333-333333333333'))->toBeFalse();
});
