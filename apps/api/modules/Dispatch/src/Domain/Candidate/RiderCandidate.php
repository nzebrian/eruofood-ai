<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Domain\Candidate;

use DateTimeImmutable;
use EruoFood\Dispatch\Domain\Enum\VehicleType;
use EruoFood\Dispatch\Domain\Vehicle\Vehicle;

/**
 * Everything known about one rider at the moment a dispatch decision is made.
 *
 * Assembled once, before eligibility runs, and passed unchanged through every
 * rule and every scoring factor. That is the point: thirteen rules each going
 * back to the database for the same rider would turn one dispatch decision into
 * a hundred queries, and — worse — two rules could see different answers if a
 * write landed between them, so a rider could be simultaneously eligible and
 * not.
 *
 * A snapshot, therefore, not a live view. It is stamped with `observedAt` so
 * anything that cares how old it is can ask rather than assume.
 */
final readonly class RiderCandidate
{
    /**
     * @param list<Vehicle> $vehicles dispatchable now: active, verified, documents current
     * @param list<Vehicle> $allVehicles every vehicle the rider still owns, retired excluded
     */
    public function __construct(
        public string $riderId,
        public string $userId,
        public string $riderStatus,
        public float $latitude,
        public float $longitude,
        public float $straightLineDistanceMetres,
        public DateTimeImmutable $locationRecordedAt,
        public ?float $locationAccuracyMetres,
        public array $vehicles,
        public int $activeDeliveryCount,
        public DateTimeImmutable $observedAt,
        public array $allVehicles = [],
        public ?DateTimeImmutable $lastAssignedAt = null,
        public int $recentAssignmentCount = 0,
        public int $consecutiveAssignmentCount = 0,
    ) {
    }

    public function locationAgeSeconds(DateTimeImmutable $now): int
    {
        return max(0, $now->getTimestamp() - $this->locationRecordedAt->getTimestamp());
    }

    /**
     * The best vehicle this rider has for a given requirement.
     *
     * Smallest sufficient, not largest available: sending a bus to carry one
     * bag of jollof because it is the roomiest vehicle in the pool is both
     * slower through traffic and more expensive to run.
     */
    public function bestVehicleFor(VehicleType $required, ?int $kg, ?int $litres): ?Vehicle
    {
        $suitable = array_values(array_filter(
            $this->vehicles,
            static fn (Vehicle $v): bool => $v->satisfies($required, $kg, $litres),
        ));

        if ($suitable === []) {
            return null;
        }

        usort(
            $suitable,
            static fn (Vehicle $a, Vehicle $b): int => $a->type()->capacityRank() <=> $b->type()->capacityRank(),
        );

        return $suitable[0];
    }

    public function hasAnyVehicle(): bool
    {
        return $this->vehicles !== [];
    }

    /**
     * Whether the rider owns any vehicle at all, verified or not.
     *
     * The difference between {@see hasAnyVehicle()} and this is the difference
     * between "register a vehicle" and "your vehicle is waiting on us" — two
     * very different things to tell a rider whose earnings just stopped.
     */
    public function ownsAnyVehicle(): bool
    {
        return $this->allVehicles !== [];
    }

    /**
     * Whether every vehicle the rider owns is held up only by lapsed paperwork.
     *
     * Distinguished so the rejection breakdown can say "expired insurance"
     * instead of the far less actionable "not verified".
     */
    public function allVehiclesHaveLapsedDocuments(DateTimeImmutable $now): bool
    {
        if ($this->allVehicles === []) {
            return false;
        }

        foreach ($this->allVehicles as $vehicle) {
            if ($vehicle->documentsAreCurrent($now)) {
                return false;
            }
        }

        return true;
    }

    /** Seconds since this rider was last given work — the idle-boost input. */
    public function idleSeconds(DateTimeImmutable $now): ?int
    {
        if ($this->lastAssignedAt === null) {
            return null;
        }

        return max(0, $now->getTimestamp() - $this->lastAssignedAt->getTimestamp());
    }
}
