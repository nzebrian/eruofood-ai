<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Domain\Vehicle;

use DateTimeImmutable;

/** Persistence port for {@see Vehicle}. */
interface VehicleRepository
{
    public function nextIdentity(): string;

    public function find(string $id): ?Vehicle;

    /**
     * Every vehicle a rider owns, retired ones included.
     *
     * Retired vehicles are returned because the rider's own list should show
     * what they have retired; callers that want usable vehicles ask for
     * {@see dispatchableFor()} instead of filtering this by hand.
     *
     * @return list<Vehicle>
     */
    public function forRider(string $riderId): array;

    /**
     * The vehicles a rider may currently work on.
     *
     * Status, verification and document expiry are all evaluated against
     * `$now`, so an insurance policy that lapsed overnight removes a vehicle
     * from service without waiting for a sweep to notice.
     *
     * @return list<Vehicle>
     */
    public function dispatchableFor(string $riderId, DateTimeImmutable $now): array;

    /**
     * Dispatchable vehicles for many riders at once, keyed by rider id.
     *
     * Candidate discovery evaluates a pool of riders in one pass. Asking per
     * rider would turn one dispatch decision into fifty queries, which is how a
     * dispatch engine becomes the slowest thing in the request.
     *
     * @param list<string> $riderIds
     * @return array<string, list<Vehicle>>
     */
    public function dispatchableForRiders(array $riderIds, DateTimeImmutable $now): array;

    /**
     * Every non-retired vehicle for many riders, keyed by rider id.
     *
     * The companion to {@see dispatchableForRiders()}. Eligibility needs both:
     * the difference between the two lists is what lets it say *why* a rider
     * has nothing usable — no vehicle at all, none verified, or documents
     * lapsed. Those are three different things to tell someone whose earnings
     * just stopped, and the dispatchable list alone cannot tell them apart.
     *
     * @param list<string> $riderIds
     * @return array<string, list<Vehicle>>
     */
    public function ownedByRiders(array $riderIds): array;

    /** The rider's primary vehicle, if they have nominated one. */
    public function primaryFor(string $riderId): ?Vehicle;

    public function countFor(string $riderId): int;

    /**
     * Vehicles awaiting a human decision, oldest first — the operator queue.
     *
     * @return list<Vehicle>
     */
    public function awaitingVerification(int $limit = 50, int $offset = 0): array;

    public function countAwaitingVerification(): int;

    /**
     * Verified vehicles whose paperwork lapses within `$days`.
     *
     * Feeds the expiry warning, so a rider hears about it before dispatch
     * stops offering them work.
     *
     * @return list<Vehicle>
     */
    public function expiringWithin(DateTimeImmutable $now, int $days, int $limit = 200): array;

    /**
     * Verified vehicles whose paperwork has already lapsed.
     *
     * @return list<Vehicle>
     */
    public function expired(DateTimeImmutable $now, int $limit = 200): array;

    /**
     * Persist, honouring the aggregate's version.
     *
     * Two operators approving the same vehicle at once, or a rider editing
     * documents while an operator approves, must not silently overwrite each
     * other — the loser is told, through {@see \EruoFood\Shared\Domain\Exception\ConcurrencyConflict}.
     */
    public function save(Vehicle $vehicle): void;

    /**
     * Clear the primary flag on every other vehicle this rider owns.
     *
     * Enforced by a partial unique index as well; this is the cooperative half,
     * so the common path does not rely on catching a constraint violation.
     */
    public function clearPrimaryExcept(string $riderId, string $keepVehicleId, DateTimeImmutable $now): void;
}
