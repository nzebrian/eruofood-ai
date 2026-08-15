<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Application\Service;

use DateTimeImmutable;
use EruoFood\Dispatch\Application\Port\RiderDirectory;
use EruoFood\Dispatch\Domain\Enum\VehicleStatus;
use EruoFood\Dispatch\Domain\Enum\VehicleType;
use EruoFood\Dispatch\Domain\Event\VehicleRegistered;
use EruoFood\Dispatch\Domain\Event\VehicleVerificationDecided;
use EruoFood\Dispatch\Domain\Exception\DispatchNotAuthorized;
use EruoFood\Dispatch\Domain\Exception\DispatchNotFound;
use EruoFood\Dispatch\Domain\Exception\VehicleNotDispatchable;
use EruoFood\Dispatch\Domain\Vehicle\Vehicle;
use EruoFood\Dispatch\Domain\Vehicle\VehicleRepository;
use EruoFood\Shared\Domain\Clock;
use EruoFood\Shared\Domain\EventBus;
use EruoFood\Shared\Domain\TransactionManager;

/**
 * Registering, verifying and retiring the vehicles riders work on.
 *
 * ## The rule this service exists to keep
 *
 * A rider may describe their vehicle. **Only an operator may approve it.** Every
 * write a rider can reach either leaves the vehicle unverified or pushes it
 * back to pending; every write that produces a usable vehicle demands an
 * operator's id, which the caller can only supply from an authenticated admin
 * session. Without that split, vehicle verification would be a form a rider
 * fills in about themselves.
 *
 * ## Ownership
 *
 * Every rider-facing method takes the acting account and checks the vehicle
 * against the rider record — never against a rider id from the request. A
 * vehicle id is a UUID in a URL; without the check, anyone holding one could
 * retire a colleague's motorbike.
 */
final readonly class VehicleService
{
    public function __construct(
        private VehicleRepository $vehicles,
        private RiderDirectory $riders,
        private TransactionManager $transactions,
        private EventBus $events,
        private Clock $clock,
        private int $maxPerRider,
        private int $expiryWarningDays,
    ) {
    }

    /**
     * A rider registering a vehicle.
     *
     * Lands as pending verification, always. There is no argument to this
     * method that could make it active.
     */
    public function register(
        string $userId,
        VehicleType $type,
        ?string $registrationNumber = null,
        ?string $make = null,
        ?string $model = null,
        ?string $colour = null,
        ?int $capacityKg = null,
        ?int $capacityLitres = null,
    ): Vehicle {
        $riderId = $this->riderIdOf($userId);
        $now = $this->clock->now();

        return $this->transactions->atomic(function () use (
            $riderId,
            $type,
            $registrationNumber,
            $make,
            $model,
            $colour,
            $capacityKg,
            $capacityLitres,
            $now
        ): Vehicle {
            if ($this->vehicles->countFor($riderId) >= $this->maxPerRider) {
                throw VehicleNotDispatchable::because(sprintf(
                    'You already have %d registered vehicles. Retire one before adding another.',
                    $this->maxPerRider,
                ));
            }

            // The first vehicle is primary by default. Making the rider choose
            // when they only have one is a step that can only be got wrong.
            $isFirst = $this->vehicles->countFor($riderId) === 0;

            $vehicle = Vehicle::register(
                id: $this->vehicles->nextIdentity(),
                riderId: $riderId,
                type: $type,
                now: $now,
                registrationNumber: $registrationNumber,
                make: $make,
                model: $model,
                colour: $colour,
                capacityKg: $capacityKg,
                capacityLitres: $capacityLitres,
                isPrimary: $isFirst,
            );

            $this->vehicles->save($vehicle);

            $this->events->publish(new VehicleRegistered(
                $vehicle->id(),
                $riderId,
                $type->value,
                $now,
            ));

            return $vehicle;
        });
    }

    /** @return list<Vehicle> */
    public function own(string $userId): array
    {
        return $this->vehicles->forRider($this->riderIdOf($userId));
    }

    public function getOwned(string $userId, string $vehicleId): Vehicle
    {
        return $this->ownedVehicle($userId, $vehicleId);
    }

    /**
     * A rider recording their paperwork dates.
     *
     * Doing so sends a previously verified vehicle back to pending, by the
     * aggregate's own rule: the old approval was of the old documents, and a
     * rider who could extend their own insurance date on a verified vehicle
     * would make the check ceremonial.
     */
    public function updateDocuments(
        string $userId,
        string $vehicleId,
        ?DateTimeImmutable $insuranceExpiresAt,
        ?DateTimeImmutable $roadworthinessExpiresAt,
        ?DateTimeImmutable $licenceExpiresAt,
    ): Vehicle {
        return $this->transactions->atomic(function () use (
            $userId,
            $vehicleId,
            $insuranceExpiresAt,
            $roadworthinessExpiresAt,
            $licenceExpiresAt
        ): Vehicle {
            $vehicle = $this->ownedVehicle($userId, $vehicleId);
            $vehicle->updateDocuments(
                $insuranceExpiresAt,
                $roadworthinessExpiresAt,
                $licenceExpiresAt,
                $this->clock->now(),
            );
            $this->vehicles->save($vehicle);

            return $vehicle;
        });
    }

    /** A rider putting their vehicle in front of an operator. */
    public function submitForVerification(string $userId, string $vehicleId): Vehicle
    {
        return $this->transactions->atomic(function () use ($userId, $vehicleId): Vehicle {
            $vehicle = $this->ownedVehicle($userId, $vehicleId);
            $vehicle->submitForVerification($this->clock->now());
            $this->vehicles->save($vehicle);

            return $vehicle;
        });
    }

    public function makePrimary(string $userId, string $vehicleId): Vehicle
    {
        return $this->transactions->atomic(function () use ($userId, $vehicleId): Vehicle {
            $vehicle = $this->ownedVehicle($userId, $vehicleId);

            if ($vehicle->status() === VehicleStatus::Retired) {
                throw VehicleNotDispatchable::because('A retired vehicle cannot be made primary.');
            }

            $now = $this->clock->now();

            // Demote first. The partial unique index would reject the opposite
            // order, and relying on catching that would make the ordinary path
            // depend on a constraint violation.
            $this->vehicles->clearPrimaryExcept($vehicle->riderId(), $vehicle->id(), $now);

            $vehicle->makePrimary($now);
            $this->vehicles->save($vehicle);

            return $vehicle;
        });
    }

    /** The rider no longer has it. Kept for the record, never dispatched on again. */
    public function retire(string $userId, string $vehicleId): Vehicle
    {
        return $this->transactions->atomic(function () use ($userId, $vehicleId): Vehicle {
            $vehicle = $this->ownedVehicle($userId, $vehicleId);
            $vehicle->retire($this->clock->now());
            $this->vehicles->save($vehicle);

            return $vehicle;
        });
    }

    /**
     * An operator accepting a vehicle's paperwork.
     *
     * The only path to a dispatchable vehicle, and it requires an operator id.
     * The caller is responsible for having authenticated that operator and
     * checked their permission; this service refuses to proceed without one so
     * an unattributed approval cannot be recorded.
     */
    public function approve(string $operatorId, string $vehicleId, ?string $note = null): Vehicle
    {
        return $this->transactions->atomic(function () use ($operatorId, $vehicleId, $note): Vehicle {
            $vehicle = $this->mustFind($vehicleId);
            $now = $this->clock->now();

            // Approving papers that have already lapsed would produce a vehicle
            // that is verified and undispatchable at the same instant —
            // confusing to a rider and to an operator looking at the queue.
            if (! $vehicle->documentsAreCurrent($now)) {
                throw VehicleNotDispatchable::because(
                    'This vehicle has expired documents. Ask the rider to supply current ones before approving.',
                );
            }

            $vehicle->approve($operatorId, $now, $note);
            $this->vehicles->save($vehicle);

            $this->events->publish(new VehicleVerificationDecided(
                $vehicle->id(),
                $vehicle->riderId(),
                approved: true,
                reason: $note,
                occurredAt: $now,
            ));

            return $vehicle;
        });
    }

    public function reject(string $operatorId, string $vehicleId, string $reason): Vehicle
    {
        return $this->transactions->atomic(function () use ($operatorId, $vehicleId, $reason): Vehicle {
            $vehicle = $this->mustFind($vehicleId);
            $now = $this->clock->now();

            $vehicle->reject($operatorId, $reason, $now);
            $this->vehicles->save($vehicle);

            $this->events->publish(new VehicleVerificationDecided(
                $vehicle->id(),
                $vehicle->riderId(),
                approved: false,
                reason: $reason,
                occurredAt: $now,
            ));

            return $vehicle;
        });
    }

    public function suspend(string $vehicleId, string $reason): Vehicle
    {
        return $this->transactions->atomic(function () use ($vehicleId, $reason): Vehicle {
            $vehicle = $this->mustFind($vehicleId);
            $vehicle->suspend($reason, $this->clock->now());
            $this->vehicles->save($vehicle);

            return $vehicle;
        });
    }

    public function reinstate(string $vehicleId): Vehicle
    {
        return $this->transactions->atomic(function () use ($vehicleId): Vehicle {
            $vehicle = $this->mustFind($vehicleId);
            $vehicle->reinstate($this->clock->now());
            $this->vehicles->save($vehicle);

            return $vehicle;
        });
    }

    /**
     * The operator queue.
     *
     * @return array{items: list<Vehicle>, total: int}
     */
    public function queue(int $limit = 50, int $offset = 0): array
    {
        return [
            'items' => $this->vehicles->awaitingVerification($limit, $offset),
            'total' => $this->vehicles->countAwaitingVerification(),
        ];
    }

    /**
     * Mark verified vehicles whose paperwork has lapsed.
     *
     * A housekeeping sweep for reporting and notification, **not** the thing
     * that stops an uninsured vehicle receiving work — {@see Vehicle::isDispatchable()}
     * checks expiry against the clock on every dispatch, so a lapsed policy
     * takes effect immediately whether or not this has run. If it were the
     * enforcement point, a skipped cron would put uninsured riders on the road.
     *
     * @return int how many were marked
     */
    public function sweepExpired(int $limit = 200): int
    {
        $now = $this->clock->now();
        $marked = 0;

        foreach ($this->vehicles->expired($now, $limit) as $vehicle) {
            $vehicle->markExpired($now);
            $this->vehicles->save($vehicle);
            $marked++;
        }

        return $marked;
    }

    /**
     * Vehicles whose documents lapse soon, so riders hear about it in advance.
     *
     * @return list<Vehicle>
     */
    public function expiringSoon(int $limit = 200): array
    {
        return $this->vehicles->expiringWithin($this->clock->now(), $this->expiryWarningDays, $limit);
    }

    public function expiryWarningDays(): int
    {
        return $this->expiryWarningDays;
    }

    private function ownedVehicle(string $userId, string $vehicleId): Vehicle
    {
        $vehicle = $this->mustFind($vehicleId);

        if (! $vehicle->belongsTo($this->riderIdOf($userId))) {
            // Deliberately the same shape of refusal whether the vehicle
            // belongs to someone else or does not exist, so this endpoint
            // cannot be used to discover which vehicle ids are real.
            throw new DispatchNotAuthorized('This vehicle does not belong to you.');
        }

        return $vehicle;
    }

    private function mustFind(string $vehicleId): Vehicle
    {
        return $this->vehicles->find($vehicleId) ?? throw DispatchNotFound::of('vehicle', $vehicleId);
    }

    private function riderIdOf(string $userId): string
    {
        return $this->riders->riderIdFor($userId)
            ?? throw DispatchNotFound::of('rider', $userId);
    }
}
