<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Application\Service;

use DateTimeImmutable;
use EruoFood\Dispatch\Application\Port\DeliveryLifecycle;
use EruoFood\Dispatch\Domain\Enum\DispatchFailureReason;
use EruoFood\Dispatch\Domain\Enum\VehicleType;
use EruoFood\Dispatch\Domain\Event\DispatchFailed;
use EruoFood\Dispatch\Domain\Event\OfferMade;
use EruoFood\Dispatch\Domain\Offer\OfferRepository;
use EruoFood\Dispatch\Domain\Offer\RiderOffer;
use EruoFood\Dispatch\Domain\Request\DispatchAttempt;
use EruoFood\Dispatch\Domain\Request\DispatchRequest;
use EruoFood\Dispatch\Domain\Request\DispatchRequestRepository;
use EruoFood\Dispatch\Domain\Scoring\ScoredCandidate;
use EruoFood\Shared\Domain\Clock;
use EruoFood\Shared\Domain\EventBus;
use EruoFood\Shared\Domain\TransactionManager;
use Throwable;

/**
 * One round of looking for a rider: discover, score, offer.
 *
 * ## The switch
 *
 * `dispatch.engine.enabled` ships **false**, exactly as M25's routed pricing
 * did. Turning it on changes how work reaches every rider on the platform, and
 * the existing manual vendor assignment keeps working either way — so this is
 * reversible with a configuration change, no deploy and no migration. Nothing
 * here runs until somebody decides it should.
 *
 * ## Every round is written down
 *
 * A {@see DispatchAttempt} is recorded whatever happens, including when nothing
 * is found. That record — radius searched, positions returned, how many
 * survived eligibility, and why the rest did not — is the difference between an
 * operator knowing they have a platform outage and knowing they have a
 * paperwork backlog. A dispatch engine that fails silently is one nobody can
 * debug at 8pm on a Friday.
 *
 * ## What this does not do
 *
 * It does not assign. A rider accepting is the only thing that assigns, and
 * that lives in {@see AssignmentService} under a lock. This only *asks*.
 */
final readonly class DispatchEngine
{
    public function __construct(
        private DispatchRequestRepository $requests,
        private OfferRepository $offers,
        private CandidateDiscoveryService $discovery,
        private ScoringService $scoring,
        private DeliveryLifecycle $deliveries,
        private TransactionManager $transactions,
        private EventBus $events,
        private Clock $clock,
        private bool $enabled,
        private int $offerTtlSeconds,
        private int $concurrentOffers,
        private int $maxAttempts,
        private int $timeBudgetSeconds,
        private bool $excludeDecliners,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Open a search for a delivery.
     *
     * Returns null when the engine is switched off, so a caller can fall back
     * to manual assignment without having to know why.
     */
    public function open(
        string $deliveryId,
        string $orderId,
        string $vendorId,
        float $pickupLat,
        float $pickupLng,
        float $dropoffLat,
        float $dropoffLng,
        VehicleType $requiredVehicleType = VehicleType::Bike,
        ?int $loadKg = null,
        ?int $loadLitres = null,
        ?string $zoneId = null,
    ): ?DispatchRequest {
        if (! $this->enabled) {
            return null;
        }

        // The partial unique index enforces this too; checking first turns the
        // ordinary duplicate — a retried queue message — into a no-op rather
        // than a constraint violation.
        $existing = $this->requests->liveForDelivery($deliveryId);

        if ($existing !== null) {
            return $existing;
        }

        $request = DispatchRequest::open(
            id: $this->requests->nextIdentity(),
            deliveryId: $deliveryId,
            orderId: $orderId,
            vendorId: $vendorId,
            pickupLat: $pickupLat,
            pickupLng: $pickupLng,
            dropoffLat: $dropoffLat,
            dropoffLng: $dropoffLng,
            now: $this->clock->now(),
            maxAttempts: $this->maxAttempts,
            timeBudgetSeconds: $this->timeBudgetSeconds,
            requiredVehicleType: $requiredVehicleType,
            loadKg: $loadKg,
            loadLitres: $loadLitres,
            zoneId: $zoneId,
        );

        $this->requests->save($request);

        return $request;
    }

    /**
     * Run one round against a claimable request.
     *
     * @return list<RiderOffer> the offers made, empty when nobody could be asked
     */
    public function attempt(string $requestId): array
    {
        return $this->transactions->atomic(function () use ($requestId): array {
            // Locked before anything else so a second worker cannot build a
            // second pool for the same delivery and offer it twice.
            $request = $this->requests->lockForUpdate($requestId);

            if ($request === null || ! $request->state()->isClaimable()) {
                return [];
            }

            $now = $this->clock->now();

            if (! $request->mayAttemptAgain($now)) {
                $this->giveUp($request, $now);

                return [];
            }

            $request->claim($now);
            $request->recordAttempt($now);

            $startedAt = $now;
            $discovered = $this->discovery->discover($request, $now);

            $offers = [];
            $best = null;

            if ($discovered->hasEligible()) {
                $ranked = $this->scoring->rank(
                    $this->withoutDecliners($request->id(), $discovered->eligible),
                    $request,
                    $now,
                );

                $offers = $this->makeOffers($request, $ranked, $now);
                $best = $ranked[0] ?? null;
            }

            $this->requests->recordAttempt(DispatchAttempt::record(
                id: $this->requests->nextIdentity(),
                requestId: $request->id(),
                attemptNumber: $request->attemptCount(),
                searchRadiusMetres: $discovered->searchRadiusMetres,
                rawCandidateCount: $discovered->rawCandidateCount,
                eligibleCandidateCount: $discovered->eligibleCount(),
                rejectionBreakdown: $discovered->rejectionBreakdown,
                startedAt: $startedAt,
                completedAt: $this->clock->now(),
                offeredRiderId: $best?->riderId(),
                offeredScore: $best?->score,
                outcome: $offers === []
                    ? ($discovered->mapWasEmpty()
                        ? DispatchFailureReason::NoCandidatesInRange
                        : DispatchFailureReason::NoEligibleRiders)
                    : null,
            ));

            if ($offers === []) {
                // Nothing offered this round. Back to pending so the next round
                // can widen or a later worker can try again — unless the budget
                // is spent, in which case operations owns it now.
                $request->release($this->clock->now());
                $this->requests->save($request);

                if (! $request->mayAttemptAgain($this->clock->now())) {
                    $this->giveUp($request, $this->clock->now(), $discovered->dominantRejection()?->value);
                }

                return [];
            }

            $this->requests->save($request);
            $this->deliveries->markOffered($request->deliveryId());

            return $offers;
        });
    }

    /**
     * @param list<ScoredCandidate> $ranked
     * @return list<RiderOffer>
     */
    private function makeOffers(DispatchRequest $request, array $ranked, DateTimeImmutable $now): array
    {
        $offers = [];

        foreach (array_slice($ranked, 0, max(1, $this->concurrentOffers)) as $candidate) {
            $offer = RiderOffer::make(
                id: $this->offers->nextIdentity(),
                requestId: $request->id(),
                riderId: $candidate->riderId(),
                deliveryId: $request->deliveryId(),
                now: $now,
                ttlSeconds: $this->offerTtlSeconds,
                vehicleId: $candidate->vehicle?->id(),
                score: $candidate->score,
                breakdown: $candidate->breakdown,
                etaSeconds: $candidate->routedEtaSeconds,
                distanceMetres: $candidate->routedDistanceMetres,
            );

            try {
                $this->offers->save($offer);
            } catch (Throwable) {
                // The rider picked up another offer between scoring and here.
                // Skipping them is right — the index is what caught it, and the
                // next candidate in the ranking is a perfectly good answer.
                continue;
            }

            $offers[] = $offer;

            $this->events->publish(new OfferMade(
                $offer->id(),
                $request->id(),
                $offer->riderId(),
                $request->deliveryId(),
                $offer->expiresAt()->format(DATE_ATOM),
                $now,
            ));
        }

        return $offers;
    }

    /**
     * Drop riders who already said no to this request.
     *
     * @param list<\EruoFood\Dispatch\Domain\Candidate\RiderCandidate> $candidates
     * @return list<\EruoFood\Dispatch\Domain\Candidate\RiderCandidate>
     */
    private function withoutDecliners(string $requestId, array $candidates): array
    {
        if (! $this->excludeDecliners) {
            return $candidates;
        }

        $declined = $this->offers->declinedRiderIds($requestId);

        if ($declined === []) {
            return $candidates;
        }

        return array_values(array_filter(
            $candidates,
            static fn ($candidate): bool => ! in_array($candidate->riderId, $declined, true),
        ));
    }

    /** End the search honestly and hand it to operations. */
    private function giveUp(DispatchRequest $request, DateTimeImmutable $now, ?string $dominantRejection = null): void
    {
        if ($request->state()->isTerminal()) {
            return;
        }

        $reason = $request->hasExpired($now)
            ? DispatchFailureReason::TimeBudgetExhausted
            : DispatchFailureReason::MaxAttemptsExhausted;

        $request->fail($reason, $now);
        $this->requests->save($request);

        // The reason and the dominant rejection travel with it, because an
        // alert that says only "dispatch failed" sends somebody to read a
        // database at 8pm on a Friday.
        $this->events->publish(new DispatchFailed(
            $request->id(),
            $request->deliveryId(),
            $reason->value,
            $dominantRejection,
            $request->attemptCount(),
            $now,
        ));
    }
}
