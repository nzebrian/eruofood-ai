<?php

declare(strict_types=1);

namespace EruoFood\Geo\Application\Service;

use DateTimeImmutable;
use EruoFood\Geo\Application\Port\GeoRateLimiter;
use EruoFood\Geo\Domain\Enum\LocationSource;
use EruoFood\Geo\Domain\Event\RiderLocationUpdated;
use EruoFood\Geo\Domain\Exception\GeoNotAuthorized;
use EruoFood\Geo\Domain\Exception\GeoNotFound;
use EruoFood\Geo\Domain\Exception\GeoQuotaExceeded;
use EruoFood\Geo\Domain\Rider\RiderLocation;
use EruoFood\Geo\Domain\Rider\RiderLocationRepository;
use EruoFood\Geo\Domain\ValueObject\Coordinates;
use EruoFood\Shared\Domain\EventBus;
use Illuminate\Support\Facades\DB;

/**
 * Where riders are — the most sensitive data in this context.
 *
 * ## What is deliberately not here
 *
 * **No history.** One row per rider, overwritten. A movement trail is what live
 * tracking will need in a later milestone, and nothing in M25 reads one.
 * Collecting a detailed record of everywhere every rider goes, for no current
 * purpose, is the clearest possible case of over-collection — and when history
 * does arrive it should arrive with a retention policy attached rather than be
 * discovered already accumulated.
 *
 * **No dispatch.** `nearby()` exists because staleness has to be filtered
 * somewhere sensible, and M26 will need it. Nothing here assigns work.
 *
 * ## Purpose limitation
 *
 * A rider writes only their own position, and that is checked against the rider
 * record rather than trusted from the request. Reads are narrower still:
 * operations sees a rider's position, and a customer sees only the rider
 * carrying their own order — a check the caller must make, which is why
 * {@see forOrder()} takes the rider id the order says is assigned rather than
 * one from the URL.
 */
final readonly class RiderLocationService
{
    public function __construct(
        private RiderLocationRepository $riders,
        private GeoRateLimiter $limiter,
        private EventBus $events,
        private int $staleAfterSeconds,
        private int $reportsPerMinute,
    ) {
    }

    /**
     * Record a rider's current position.
     *
     * Rate-limited per rider. A device stuck in a loop would otherwise write
     * thousands of rows a minute, and the limit is the difference between a
     * buggy build and a database incident.
     *
     * The published event deliberately carries **no coordinates** — only that a
     * rider reported. Anything that needs the position reads it through this
     * service, where authorisation applies; an event fans out to subscribers
     * that have no such check.
     */
    public function report(
        string $riderId,
        string $userId,
        Coordinates $coordinates,
        ?float $accuracyMetres = null,
        ?float $headingDegrees = null,
        ?float $speedMps = null,
        ?DateTimeImmutable $recordedAt = null,
    ): RiderLocation {
        $this->assertRiderBelongsToUser($riderId, $userId);

        if (! $this->limiter->attempt('rider:'.$riderId, $this->reportsPerMinute)) {
            throw GeoQuotaExceeded::forCaller();
        }

        $now = new DateTimeImmutable();

        $location = RiderLocation::report(
            $riderId,
            $userId,
            $coordinates,
            // A device clock can be wrong, and a fix stamped in the future
            // would never look stale. Anything ahead of now is pulled back.
            $recordedAt !== null && $recordedAt <= $now ? $recordedAt : $now,
            $accuracyMetres,
            $headingDegrees,
            $speedMps,
            LocationSource::Device,
        );

        $this->riders->save($location);

        $this->events->publish(new RiderLocationUpdated(
            riderId: $riderId,
            recordedAt: $location->recordedAt()->format(DATE_ATOM),
            accuracyMetres: $location->accuracyMetres(),
            source: $location->source()->value,
        ));

        return $location;
    }

    /** A rider reading their own last reported position. */
    public function own(string $riderId, string $userId): RiderLocation
    {
        $location = $this->riders->findByRider($riderId);

        if ($location === null) {
            throw GeoNotFound::of('rider location', $riderId);
        }

        if (! $location->belongsTo($userId)) {
            throw new GeoNotAuthorized('That rider position does not belong to you.');
        }

        return $location;
    }

    /**
     * A rider's position, for whoever is entitled to it.
     *
     * The entitlement is the caller's to establish — an operator by permission,
     * a customer by holding an order this rider is carrying. This method does
     * not accept a user id, because there is no single rule it could apply;
     * pretending otherwise would produce a check that looks like authorisation
     * and is not.
     */
    public function positionOf(string $riderId): ?RiderLocation
    {
        return $this->riders->findByRider($riderId);
    }

    /**
     * Riders near a point whose position is recent enough to mean anything.
     *
     * The shape M26 dispatch will need. Staleness is applied here rather than
     * by the caller so "riders near this restaurant" cannot quietly come to
     * mean "riders who were near it last Tuesday".
     *
     * @return list<array{location: RiderLocation, distanceMetres: float}>
     */
    public function nearby(Coordinates $centre, float $radiusMetres, int $limit = 25): array
    {
        $freshSince = (new DateTimeImmutable())->modify(sprintf('-%d seconds', $this->staleAfterSeconds));

        return $this->riders->nearby($centre, $radiusMetres, $freshSince, $limit);
    }

    /** How many riders have reported recently — a health signal, not a location read. */
    public function activeRiderCount(): int
    {
        return $this->riders->countFreshSince(
            (new DateTimeImmutable())->modify(sprintf('-%d seconds', $this->staleAfterSeconds)),
        );
    }

    /**
     * Forget a rider's position when they go offline.
     *
     * A real delete, unlike almost everything else on the platform. There is
     * nothing to audit in a position a rider is entitled to stop sharing, and
     * keeping it would be retaining location data past the purpose that
     * justified collecting it.
     */
    public function goOffline(string $riderId, string $userId): void
    {
        $this->assertRiderBelongsToUser($riderId, $userId);
        $this->riders->forget($riderId);
    }

    public function isStale(RiderLocation $location): bool
    {
        return $location->isStale(new DateTimeImmutable(), $this->staleAfterSeconds);
    }

    public function staleAfterSeconds(): int
    {
        return $this->staleAfterSeconds;
    }

    /**
     * A rider may only ever write their own position.
     *
     * Checked against the rider record rather than against whatever the request
     * claimed. A rider id is a UUID in a URL; without this, anybody holding one
     * could move somebody else's marker across Lagos.
     */
    private function assertRiderBelongsToUser(string $riderId, string $userId): void
    {
        $ownerId = DB::table('marketplace_riders')->where('id', $riderId)->value('user_id');

        if ($ownerId === null) {
            throw GeoNotFound::of('rider', $riderId);
        }

        if ($ownerId !== $userId) {
            throw new GeoNotAuthorized('You may only report your own location.');
        }
    }
}
