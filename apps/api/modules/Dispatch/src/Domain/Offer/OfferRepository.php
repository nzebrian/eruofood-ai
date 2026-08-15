<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Domain\Offer;

use DateTimeImmutable;

/** Persistence port for {@see RiderOffer}. */
interface OfferRepository
{
    public function nextIdentity(): string;

    public function find(string $id): ?RiderOffer;

    /**
     * Take the row's lock before reading it.
     *
     * `SELECT … FOR UPDATE`. The rider's Accept and the expiry sweep can reach
     * the same offer at the same instant; without the lock both read `offered`
     * and both act.
     *
     * Must be called inside a transaction — outside one the lock is released
     * immediately and the call is worse than useless, because it looks safe.
     */
    public function lockForUpdate(string $id): ?RiderOffer;

    /** The offer a rider is currently looking at, if any. */
    public function liveForRider(string $riderId): ?RiderOffer;

    /**
     * Offers still open for a request.
     *
     * @return list<RiderOffer>
     */
    public function liveForRequest(string $requestId): array;

    /**
     * Riders who declined this request, so they are not asked again.
     *
     * @return list<string>
     */
    public function declinedRiderIds(string $requestId): array;

    /**
     * Offers whose TTL has run out and which nobody has answered.
     *
     * @return list<RiderOffer>
     */
    public function expiredUnanswered(DateTimeImmutable $now, int $limit = 200): array;

    /** How many offers are sitting on riders' screens right now — a health signal. */
    public function countLive(): int;

    /** @return list<RiderOffer> */
    public function forRequest(string $requestId): array;

    public function save(RiderOffer $offer): void;
}
