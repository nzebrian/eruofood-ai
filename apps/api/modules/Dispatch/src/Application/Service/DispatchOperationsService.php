<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Application\Service;

use EruoFood\Dispatch\Application\Port\DeliveryLifecycle;
use EruoFood\Dispatch\Application\Port\RiderDirectory;
use EruoFood\Dispatch\Domain\Assignment\Assignment;
use EruoFood\Dispatch\Domain\Assignment\AssignmentRepository;
use EruoFood\Dispatch\Domain\Enum\AssignmentState;
use EruoFood\Dispatch\Domain\Enum\DispatchFailureReason;
use EruoFood\Dispatch\Domain\Enum\DispatchState;
use EruoFood\Dispatch\Domain\Exception\AssignmentConflict;
use EruoFood\Dispatch\Domain\Exception\DispatchNotFound;
use EruoFood\Dispatch\Domain\Offer\OfferRepository;
use EruoFood\Dispatch\Domain\Request\DispatchRequest;
use EruoFood\Dispatch\Domain\Request\DispatchRequestRepository;
use EruoFood\Dispatch\Domain\Vehicle\VehicleRepository;
use EruoFood\Geo\Application\Service\RiderLocationService;
use EruoFood\Shared\Domain\Clock;
use EruoFood\Shared\Domain\TransactionManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * What operations needs to run dispatch, and the break-glass levers.
 *
 * ## The reads answer questions people actually have at 8pm on a Friday
 *
 * Not "here is a table". "How many customers are waiting and for how long",
 * "which searches failed and why", "how many riders could take work right now".
 * A dashboard that cannot answer those is a dashboard nobody opens twice.
 *
 * ## The writes are break-glass, and every one is audited by the caller
 *
 * Manual assignment, forced reassignment and cancellation exist because
 * automation fails and somebody has to be able to fix it. They also take a
 * delivery off one rider and give it to another, which changes who earns — so
 * they are behind `dispatch.manage` rather than `dispatch.read`, and the
 * controller records every use in the append-only audit log.
 *
 * ## No coordinates leave here
 *
 * `riderAvailability()` counts riders; it does not list where they are. An
 * operational dashboard needs to know the fleet is thin, not to be a live map
 * of where a workforce is standing. M25's presenter remains the only thing that
 * renders a position, under its own authorisation.
 */
final readonly class DispatchOperationsService
{
    public function __construct(
        private DispatchRequestRepository $requests,
        private AssignmentRepository $assignments,
        private OfferRepository $offers,
        private VehicleRepository $vehicles,
        private RiderDirectory $riders,
        private RiderLocationService $locations,
        private DeliveryLifecycle $deliveries,
        private ReassignmentService $reassignment,
        private TransactionManager $transactions,
        private Clock $clock,
        private bool $allowManualOverride,
    ) {
    }

    /**
     * Deliveries still looking for a rider, longest wait first.
     *
     * Ordered by how long the customer has been waiting rather than by when the
     * request was created, which is the same thing today and would stop being
     * so the moment anybody adds a priority tier. Naming the intent means the
     * ordering survives that change.
     *
     * @return list<DispatchRequest>
     */
    public function unassignedQueue(int $limit = 50): array
    {
        return $this->requests->claimable($limit);
    }

    /**
     * Deliveries somebody is carrying right now.
     *
     * @return list<Assignment>
     */
    public function activeAssignments(int $limit = 100): array
    {
        return $this->assignments->active($limit);
    }

    /**
     * Searches that ended without a rider, most recent first.
     *
     * @return list<DispatchRequest>
     */
    public function failures(int $limit = 50): array
    {
        return $this->requests->failed($limit);
    }

    /**
     * Everything tried for one delivery request, in order.
     *
     * @return array{request: DispatchRequest, attempts: list<\EruoFood\Dispatch\Domain\Request\DispatchAttempt>, offers: list<\EruoFood\Dispatch\Domain\Offer\RiderOffer>}
     */
    public function history(string $requestId): array
    {
        $request = $this->requests->find($requestId)
            ?? throw DispatchNotFound::of('dispatch request', $requestId);

        return [
            'request' => $request,
            'attempts' => $this->requests->attemptsFor($requestId),
            'offers' => $this->offers->forRequest($requestId),
        ];
    }

    /**
     * How much of the fleet could actually take work.
     *
     * Counts, never positions. The gap between "riders reporting a position"
     * and "riders with a dispatchable vehicle" is the number that tells an
     * operations team whether they have a supply problem or a paperwork one.
     *
     * @return array<string, int>
     */
    public function riderAvailability(): array
    {
        $now = $this->clock->now();

        $online = (int) DB::table('marketplace_riders')
            ->whereIn('status', ['online', 'available', 'idle'])
            ->count();

        $busy = (int) DB::table('dispatch_assignments')
            ->whereIn('state', AssignmentState::occupyingValues())
            ->distinct()
            ->count('rider_id');

        return [
            'riders_online' => $online,
            'riders_reporting_position' => $this->locations->activeRiderCount(),
            'riders_carrying_a_delivery' => $busy,
            'vehicles_awaiting_verification' => $this->vehicles->countAwaitingVerification(),
            'vehicles_expiring_soon' => count($this->vehicles->expiringWithin($now, 14, 500)),
            'vehicles_expired' => count($this->vehicles->expired($now, 500)),
        ];
    }

    /**
     * Is dispatch healthy right now?
     *
     * Deliberately opinionated: it says whether the engine is on, how many
     * customers are waiting and how long the oldest has been. A health endpoint
     * that returns raw counters makes every consumer decide for itself what
     * "unhealthy" means, and they will all decide differently.
     *
     * @return array<string, mixed>
     */
    public function health(bool $engineEnabled): array
    {
        $now = $this->clock->now();
        $queue = $this->unassignedQueue(200);

        $oldestWait = 0;

        foreach ($queue as $request) {
            $oldestWait = max($oldestWait, $request->elapsedSeconds($now));
        }

        return [
            'engine_enabled' => $engineEnabled,
            'manual_override_allowed' => $this->allowManualOverride,
            'searches_waiting' => count($queue),
            'oldest_wait_seconds' => $oldestWait,
            'searches_past_deadline' => count($this->requests->timedOut($now, 200)),
            'offers_awaiting_answer' => $this->offers->countLive(),
            'availability' => $this->riderAvailability(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Break-glass. Audited by the caller, every time.
    |--------------------------------------------------------------------------
    */

    /**
     * An operator puts a delivery on a specific rider.
     *
     * The override that exists because automation fails. It still goes through
     * the same exclusivity guarantees — the partial unique indexes do not know
     * or care that a human made this decision, which is exactly why they are
     * the last line.
     *
     * Eligibility is **not** re-checked here, and that is the deliberate part
     * of "override": an operator who can see the rider standing in front of the
     * restaurant should not be blocked because the rider's phone last reported
     * six minutes ago. The audit entry is what makes that accountable.
     */
    public function manuallyAssign(string $requestId, string $riderId): Assignment
    {
        if (! $this->allowManualOverride) {
            throw new RuntimeException('Manual dispatch override is disabled.');
        }

        return $this->transactions->atomic(function () use ($requestId, $riderId): Assignment {
            $request = $this->requests->lockForUpdate($requestId)
                ?? throw DispatchNotFound::of('dispatch request', $requestId);

            if ($request->state()->isTerminal()) {
                throw AssignmentConflict::deliveryTaken($request->deliveryId());
            }

            if ($this->riders->summary($riderId) === null) {
                throw DispatchNotFound::of('rider', $riderId);
            }

            if ($this->assignments->activeForRider($riderId) !== null) {
                throw AssignmentConflict::riderTaken($riderId);
            }

            $now = $this->clock->now();
            $primaryVehicle = $this->vehicles->primaryFor($riderId);

            $assignment = Assignment::accept(
                id: $this->assignments->nextIdentity(),
                requestId: $request->id(),
                // A manual assignment has no offer behind it. A synthetic id
                // keeps the one-assignment-per-offer index meaningful without
                // inventing an offer nobody ever saw.
                offerId: $this->assignments->nextIdentity(),
                deliveryId: $request->deliveryId(),
                riderId: $riderId,
                now: $now,
                vehicleId: $primaryVehicle?->id(),
            );

            try {
                $this->assignments->save($assignment);
            } catch (UniqueConstraintViolationException) {
                throw AssignmentConflict::deliveryTaken($request->deliveryId());
            }

            $request->assign($riderId, $now);
            $this->requests->save($request);
            $this->deliveries->riderAccepted($request->deliveryId(), $riderId);

            // Withdraw anything still on another rider's screen for this
            // delivery — they will tap it and be refused otherwise, which reads
            // as the app being broken.
            foreach ($this->offers->liveForRequest($request->id()) as $offer) {
                $offer->cancel($now);
                $this->offers->save($offer);
            }

            return $assignment;
        });
    }

    /** An operator takes a delivery off the rider holding it. */
    public function forceReassign(string $assignmentId, string $reason): ?DispatchRequest
    {
        return $this->reassignment->reassign($assignmentId, $reason);
    }

    /** An operator stops a search. */
    public function cancelRequest(string $requestId, string $reason): DispatchRequest
    {
        return $this->transactions->atomic(function () use ($requestId): DispatchRequest {
            $request = $this->requests->lockForUpdate($requestId)
                ?? throw DispatchNotFound::of('dispatch request', $requestId);

            $now = $this->clock->now();
            $request->cancel($now);
            $this->requests->save($request);

            foreach ($this->offers->liveForRequest($request->id()) as $offer) {
                $offer->cancel($now);
                $this->offers->save($offer);
            }

            return $request;
        });
    }

    /**
     * The reasons a search can end, for a dashboard's filter list.
     *
     * @return list<array{value: string, retryable: bool, warrants_alert: bool}>
     */
    public function failureReasons(): array
    {
        return array_map(
            static fn (DispatchFailureReason $reason): array => [
                'value' => $reason->value,
                'retryable' => $reason->isRetryable(),
                'warrants_alert' => $reason->warrantsAlert(),
            ],
            DispatchFailureReason::cases(),
        );
    }

    /**
     * States a request can be in, for the same reason.
     *
     * @return list<string>
     */
    public function requestStates(): array
    {
        return array_map(static fn (DispatchState $state): string => $state->value, DispatchState::cases());
    }
}
