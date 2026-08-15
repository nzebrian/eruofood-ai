<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Domain\Enum;

/**
 * The vehicles the platform dispatches on.
 *
 * A controlled vocabulary, deliberately. Before M26 a rider's vehicle was a
 * free-form string on `marketplace_riders`, validated only at the HTTP edge
 * against a list that did not even match this one — it had "foot" and no
 * tricycle, which is the commonest delivery vehicle in several Nigerian
 * markets. A dispatch engine cannot match a load to a vehicle whose type is
 * whatever somebody typed.
 *
 * There is no `FOOT`. Walking is not a vehicle, and a rider whose legacy record
 * said so keeps their account but cannot be dispatched until they register a
 * real one — see `…_backfill_vehicles_from_marketplace_riders`.
 *
 * Capability knowledge lives here rather than in scattered `match` statements,
 * so a new type is one case plus its answers, not a hunt through the codebase.
 */
enum VehicleType: string
{
    case Bike = 'bike';
    case Tricycle = 'tricycle';
    case Car = 'car';
    case Bus = 'bus';

    /**
     * Whether this vehicle can serve a request asking for `$required`.
     *
     * Deliberately directional. A request for a bike means "at least a bike" —
     * a car can carry what a bike can. A request for a bus cannot be met by a
     * bike. Encoding the asymmetry once stops every scoring factor and
     * eligibility rule from re-deriving it, and getting it backwards.
     */
    public function satisfies(self $required): bool
    {
        return $this->capacityRank() >= $required->capacityRank();
    }

    /**
     * Rough ordering by what the vehicle can carry.
     *
     * Not a quality ranking — a bike beats a bus through Lagos traffic, which
     * is what {@see isTrafficAgile()} is for. This orders capacity only.
     */
    public function capacityRank(): int
    {
        return match ($this) {
            self::Bike => 1,
            self::Tricycle => 2,
            self::Car => 3,
            self::Bus => 4,
        };
    }

    /** A sensible default when a rider does not state their vehicle's capacity. */
    public function defaultCapacityKg(): int
    {
        return match ($this) {
            self::Bike => 20,
            self::Tricycle => 150,
            self::Car => 300,
            self::Bus => 1_000,
        };
    }

    /** A sensible default volume, in litres. */
    public function defaultCapacityLitres(): int
    {
        return match ($this) {
            self::Bike => 60,
            self::Tricycle => 400,
            self::Car => 500,
            self::Bus => 3_000,
        };
    }

    /**
     * Whether a load fits.
     *
     * A rider's stated capacity may lower what the vehicle is trusted with, but
     * never raise it past the type's default. A car with the back seats full
     * carries less than a car — believing that is safe. A bicycle whose owner
     * typed "500 kg" is a data-entry error, not a bicycle that can carry half a
     * tonne, and dispatching on it would put an impossible load on somebody's
     * back.
     *
     * The cap is deliberate and it does cost something: an unusually large van
     * cannot advertise more than the bus default. That is the right way round
     * while capacity is self-declared and unverified — the ceiling can be
     * raised for a type once someone checks, which is exactly what vehicle
     * verification is for.
     */
    public function canCarry(?int $kg, ?int $litres, ?int $statedKg = null, ?int $statedLitres = null): bool
    {
        $capacityKg = min($statedKg ?? $this->defaultCapacityKg(), $this->defaultCapacityKg());
        $capacityLitres = min($statedLitres ?? $this->defaultCapacityLitres(), $this->defaultCapacityLitres());

        return ($kg === null || $kg <= $capacityKg)
            && ($litres === null || $litres <= $capacityLitres);
    }

    /**
     * Whether the vehicle gets through congestion well.
     *
     * Feeds scoring rather than eligibility: in heavy traffic a bike genuinely
     * arrives sooner than a bus, and the routed ETA alone does not always
     * capture that.
     */
    public function isTrafficAgile(): bool
    {
        return $this === self::Bike || $this === self::Tricycle;
    }

    /**
     * The M25 travel mode to route this vehicle with.
     *
     * Returned as the string value of {@see \EruoFood\Geo\Domain\Enum\TravelMode}
     * so Dispatch takes no compile-time dependency on Geo's enum — the same
     * discipline the published contracts use.
     */
    public function travelMode(): string
    {
        return match ($this) {
            self::Bike, self::Tricycle => 'two_wheeler',
            self::Car, self::Bus => 'driving',
        };
    }

    /**
     * Whether a registration number is expected.
     *
     * A bicycle-class bike often has none in Nigeria, so requiring one would
     * exclude legitimate riders. Anything larger is expected to be plated.
     */
    public function requiresRegistration(): bool
    {
        return $this !== self::Bike;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }

    /**
     * Map a legacy `marketplace_riders.vehicle_type` string, or null when it
     * has no supported equivalent.
     *
     * Returning null rather than guessing is the point. "foot" and anything
     * unrecognised must produce *no vehicle* — inventing one would put a rider
     * on the road carrying a load their actual transport cannot take.
     */
    public static function fromLegacy(?string $legacy): ?self
    {
        return match (mb_strtolower(trim((string) $legacy))) {
            'bicycle', 'motorbike', 'bike', 'motorcycle' => self::Bike,
            'tricycle', 'keke', 'keke napep' => self::Tricycle,
            'car', 'sedan' => self::Car,
            'bus', 'van' => self::Bus,
            default => null,
        };
    }
}
