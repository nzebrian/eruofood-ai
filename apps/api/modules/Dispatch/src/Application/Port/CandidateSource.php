<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Application\Port;

use DateTimeImmutable;
use EruoFood\Dispatch\Domain\Candidate\RiderCandidate;
use EruoFood\Dispatch\Domain\Request\DispatchRequest;

/**
 * Where the pool of riders to consider comes from.
 *
 * Behind this port sits M25: rider positions, the bounding-box prefilter, the
 * haversine pass. **Dispatch does not reimplement any of it.** A second
 * geographic search would be a second thing to keep correct, and the two would
 * eventually disagree about which riders are near a restaurant.
 *
 * The port exists so discovery can be tested without a geographic stack, and so
 * the day PostGIS replaces the bounding-box prefilter, exactly one class
 * changes.
 */
interface CandidateSource
{
    /**
     * Riders near the pickup point, with everything a dispatch decision needs.
     *
     * Assembled in as few queries as the shape allows — a per-rider lookup here
     * would turn one dispatch into a hundred round trips. Staleness is filtered
     * by M25 itself, so "nearby riders" cannot quietly come to mean "riders who
     * were nearby last Tuesday".
     *
     * @return list<RiderCandidate>
     */
    public function near(
        DispatchRequest $request,
        float $radiusMetres,
        int $limit,
        DateTimeImmutable $now,
    ): array;

    /**
     * One rider's current picture, regardless of where they are.
     *
     * Used by the acceptance-time re-check inside the assignment lock, which
     * asks "may this specific rider still do this job?" rather than "who is
     * nearby?". No radius, because a rider who has ridden a kilometre since the
     * offer was made has not thereby become ineligible — distance was settled
     * when they were chosen.
     *
     * Null when the rider has no position on record at all.
     */
    public function forRider(
        string $riderId,
        DispatchRequest $request,
        DateTimeImmutable $now,
    ): ?RiderCandidate;
}
