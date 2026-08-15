<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Infrastructure\Geo;

use DateTimeImmutable;
use EruoFood\Dispatch\Application\Port\CandidateSource;
use EruoFood\Dispatch\Application\Port\RiderDirectory;
use EruoFood\Dispatch\Application\Port\RiderWorkloadQuery;
use EruoFood\Dispatch\Domain\Candidate\RiderCandidate;
use EruoFood\Dispatch\Domain\Request\DispatchRequest;
use EruoFood\Dispatch\Domain\Vehicle\VehicleRepository;
use EruoFood\Geo\Application\Service\RiderLocationService;
use EruoFood\Geo\Domain\ValueObject\Coordinates;
use EruoFood\Geo\Domain\ValueObject\Haversine;

/**
 * Builds the candidate pool out of M25's geographic services.
 *
 * The whole adapter is four batched reads, in this order:
 *
 * 1. **M25** for riders near the pickup point, already filtered for staleness.
 * 2. The rider directory, for status and account id.
 * 3. Vehicles — dispatchable and owned — for the whole pool at once.
 * 4. Workload and assignment history, for the whole pool at once.
 *
 * Batched deliberately. Asking per rider would turn one dispatch decision into
 * a hundred queries; worse, two rules could then see different answers if a
 * write landed between them, so a rider could be eligible and not eligible in
 * the same decision.
 *
 * Nothing here re-derives geography. The bounding box, the haversine pass and
 * the staleness cutoff all live in M25 and stay there — a second geographic
 * search would be a second thing to keep correct, and the two would eventually
 * disagree about who is near a restaurant.
 */
final readonly class GeoCandidateSource implements CandidateSource
{
    public function __construct(
        private RiderLocationService $locations,
        private RiderDirectory $riders,
        private VehicleRepository $vehicles,
        private RiderWorkloadQuery $workload,
        private int $fairnessWindowSeconds,
    ) {
    }

    public function near(
        DispatchRequest $request,
        float $radiusMetres,
        int $limit,
        DateTimeImmutable $now,
    ): array {
        $nearby = $this->locations->nearby(
            new Coordinates($request->pickupLat(), $request->pickupLng()),
            $radiusMetres,
            $limit,
        );

        if ($nearby === []) {
            return [];
        }

        $riderIds = array_map(
            static fn (array $row): string => $row['location']->riderId(),
            $nearby,
        );

        $summaries = $this->riders->summaries($riderIds);
        $dispatchable = $this->vehicles->dispatchableForRiders($riderIds, $now);
        $owned = $this->vehicles->ownedByRiders($riderIds);
        $activeCounts = $this->workload->activeDeliveryCounts($riderIds);
        $history = $this->workload->assignmentHistory(
            $riderIds,
            $now->modify(sprintf('-%d seconds', $this->fairnessWindowSeconds)),
        );

        $candidates = [];

        foreach ($nearby as $row) {
            $location = $row['location'];
            $riderId = $location->riderId();
            $summary = $summaries[$riderId] ?? null;

            if ($summary === null) {
                // A position with no rider record behind it. Skipping is right:
                // there is nobody to offer the work to, and inventing a default
                // status would make a ghost eligible.
                continue;
            }

            $riderHistory = $history[$riderId] ?? null;

            $candidates[] = new RiderCandidate(
                riderId: $riderId,
                userId: $summary['user_id'],
                riderStatus: $summary['status'],
                latitude: $location->coordinates()->latitude,
                longitude: $location->coordinates()->longitude,
                straightLineDistanceMetres: $row['distanceMetres'],
                locationRecordedAt: $location->recordedAt(),
                locationAccuracyMetres: $location->accuracyMetres(),
                vehicles: $dispatchable[$riderId] ?? [],
                activeDeliveryCount: $activeCounts[$riderId] ?? 0,
                observedAt: $now,
                allVehicles: $owned[$riderId] ?? [],
                lastAssignedAt: $riderHistory['last_assigned_at'] ?? null,
                recentAssignmentCount: $riderHistory['recent_count'] ?? 0,
                consecutiveAssignmentCount: $riderHistory['consecutive_count'] ?? 0,
            );
        }

        return $candidates;
    }

    public function forRider(string $riderId, DispatchRequest $request, DateTimeImmutable $now): ?RiderCandidate
    {
        $summary = $this->riders->summary($riderId);

        if ($summary === null) {
            return null;
        }

        $location = $this->locations->positionOf($riderId);

        if ($location === null) {
            return null;
        }

        $history = $this->workload->assignmentHistory(
            [$riderId],
            $now->modify(sprintf('-%d seconds', $this->fairnessWindowSeconds)),
        )[$riderId] ?? null;

        return new RiderCandidate(
            riderId: $riderId,
            userId: $summary['user_id'],
            riderStatus: $summary['status'],
            latitude: $location->coordinates()->latitude,
            longitude: $location->coordinates()->longitude,
            // Measured from the pickup so the value means the same thing it
            // does in a discovery candidate, even though nothing at acceptance
            // time scores on it.
            straightLineDistanceMetres: Haversine::metres(
                new Coordinates($request->pickupLat(), $request->pickupLng()),
                $location->coordinates(),
            ),
            locationRecordedAt: $location->recordedAt(),
            locationAccuracyMetres: $location->accuracyMetres(),
            vehicles: $this->vehicles->dispatchableFor($riderId, $now),
            activeDeliveryCount: $this->workload->activeDeliveryCounts([$riderId])[$riderId] ?? 0,
            observedAt: $now,
            allVehicles: $this->vehicles->ownedByRiders([$riderId])[$riderId] ?? [],
            lastAssignedAt: $history['last_assigned_at'] ?? null,
            recentAssignmentCount: $history['recent_count'] ?? 0,
            consecutiveAssignmentCount: $history['consecutive_count'] ?? 0,
        );
    }
}
