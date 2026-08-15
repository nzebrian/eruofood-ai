<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Application\Port;

/**
 * The little Dispatch needs to know about a rider record.
 *
 * Riders live in Marketplace. Dispatch does not import its aggregate, its
 * repository or its Eloquent model — that would be a compile-time dependency
 * between two contexts and the first crack in the module boundary. It asks
 * these four questions through a port instead, and the adapter answers them
 * with a soft-referenced read.
 *
 * Note what is *not* here: nothing that changes a rider. Dispatch decides who
 * gets offered work; it never edits the rider record, and a port with no write
 * method is the cheapest way to keep that true.
 */
interface RiderDirectory
{
    /** The account that owns this rider record, or null if there is no such rider. */
    public function ownerOf(string $riderId): ?string;

    /** The rider record belonging to an account, if that account has one. */
    public function riderIdFor(string $userId): ?string;

    /**
     * A rider's operational summary, or null if unknown.
     *
     * `status` is Marketplace's own rider status string — `online`, `offline`,
     * `busy`, `suspended`. Dispatch interprets it in one eligibility rule
     * rather than scattering the vocabulary through the context.
     *
     * @return array{id: string, user_id: string, name: string, phone: string, status: string, vehicle_type: string|null}|null
     */
    public function summary(string $riderId): ?array;

    /**
     * Summaries for many riders at once, keyed by rider id.
     *
     * Candidate discovery evaluates a pool in one pass; asking per rider would
     * turn one dispatch decision into fifty round trips.
     *
     * @param list<string> $riderIds
     * @return array<string, array{id: string, user_id: string, name: string, phone: string, status: string, vehicle_type: string|null}>
     */
    public function summaries(array $riderIds): array;
}
