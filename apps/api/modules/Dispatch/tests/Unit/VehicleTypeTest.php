<?php

declare(strict_types=1);

use EruoFood\Dispatch\Domain\Enum\VehicleType;

/**
 * M26 — the controlled vocabulary that replaced a free-form string.
 *
 * Before M26 a rider's vehicle was whatever they typed into
 * `marketplace_riders.vehicle_type`, validated at the HTTP edge against a list
 * that did not match anything the platform could act on: it had "foot" and no
 * tricycle, which is one of the commonest delivery vehicles in Nigerian
 * markets. These tests hold the replacement to the shape dispatch depends on.
 */

it('has exactly the four approved types and no walking option', function (): void {
    expect(VehicleType::values())->toBe(['bike', 'tricycle', 'car', 'bus']);

    // Walking is not a vehicle. A rider whose legacy record said "foot" keeps
    // their account; they simply cannot be dispatched until they register a
    // real one. If a FOOT case were ever added, this fails.
    expect(VehicleType::tryFrom('foot'))->toBeNull();
});

/**
 * Suitability is directional, and getting it backwards is the kind of bug that
 * quietly puts a 40 kg catering order on a bicycle.
 */
it('lets a larger vehicle serve a smaller request but never the reverse', function (): void {
    expect(VehicleType::Car->satisfies(VehicleType::Bike))->toBeTrue()
        ->and(VehicleType::Bus->satisfies(VehicleType::Tricycle))->toBeTrue()
        ->and(VehicleType::Bike->satisfies(VehicleType::Bike))->toBeTrue();

    expect(VehicleType::Bike->satisfies(VehicleType::Car))->toBeFalse()
        ->and(VehicleType::Tricycle->satisfies(VehicleType::Bus))->toBeFalse();
});

it('orders capacity strictly, so scoring cannot tie two different vehicles', function (): void {
    $ranks = array_map(
        static fn (VehicleType $t): int => $t->capacityRank(),
        VehicleType::cases(),
    );

    expect($ranks)->toBe([1, 2, 3, 4])
        ->and(count(array_unique($ranks)))->toBe(count($ranks));
});

/**
 * A stated capacity must never *raise* what a vehicle is trusted with beyond
 * its type. A rider typing "500 kg" against a bicycle is a data-entry error,
 * not a bicycle that can carry 500 kg.
 */
it('caps a stated capacity at the type default rather than trusting the rider', function (): void {
    expect(VehicleType::Bike->canCarry(500, null, 500, null))->toBeFalse()
        ->and(VehicleType::Bike->canCarry(10, null, null, null))->toBeTrue()
        ->and(VehicleType::Bus->canCarry(500, null, null, null))->toBeTrue();
});

it('treats an unstated load as carryable, because nothing was asked for', function (): void {
    expect(VehicleType::Bike->canCarry(null, null, null, null))->toBeTrue();
});

it('lowers a stated capacity below the type default when the rider says so', function (): void {
    // A car with the back seats full carries less than a car. Believing the
    // rider downward is safe; believing them upward is not.
    expect(VehicleType::Car->canCarry(100, null, 50, null))->toBeFalse()
        ->and(VehicleType::Car->canCarry(40, null, 50, null))->toBeTrue();
});

it('routes two-wheelers through a different travel mode than four', function (): void {
    expect(VehicleType::Bike->travelMode())->toBe('two_wheeler')
        ->and(VehicleType::Car->travelMode())->toBe('driving')
        ->and(VehicleType::Bus->travelMode())->toBe('driving');
});

it('requires a registration number for everything but a bike', function (): void {
    expect(VehicleType::Bike->requiresRegistration())->toBeFalse()
        ->and(VehicleType::Tricycle->requiresRegistration())->toBeTrue()
        ->and(VehicleType::Car->requiresRegistration())->toBeTrue()
        ->and(VehicleType::Bus->requiresRegistration())->toBeTrue();
});

/**
 * The legacy mapping the M26 migration decision fixed.
 *
 * The critical half is the *null* half: 'foot' and anything unrecognised must
 * map to nothing. Inventing a type for them is the one genuinely unsafe
 * outcome — it would put a rider in the system on a motorbike they do not have.
 */
it('maps known legacy strings and refuses to guess at the rest', function (): void {
    expect(VehicleType::fromLegacy('bicycle'))->toBe(VehicleType::Bike)
        ->and(VehicleType::fromLegacy('motorbike'))->toBe(VehicleType::Bike)
        ->and(VehicleType::fromLegacy('car'))->toBe(VehicleType::Car)
        ->and(VehicleType::fromLegacy('keke'))->toBe(VehicleType::Tricycle);

    expect(VehicleType::fromLegacy('foot'))->toBeNull()
        ->and(VehicleType::fromLegacy('skateboard'))->toBeNull()
        ->and(VehicleType::fromLegacy(''))->toBeNull()
        ->and(VehicleType::fromLegacy(null))->toBeNull();
});

it('normalises case and surrounding whitespace in legacy values', function (): void {
    expect(VehicleType::fromLegacy('  MotorBike '))->toBe(VehicleType::Bike);
});
