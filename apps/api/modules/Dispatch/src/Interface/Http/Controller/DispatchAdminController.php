<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Interface\Http\Controller;

use DateTimeImmutable;
use EruoFood\Admin\Application\Service\AuditService;
use EruoFood\Admin\Domain\Enum\AuditCategory;
use EruoFood\Dispatch\Application\Service\DispatchEngine;
use EruoFood\Dispatch\Application\Service\DispatchOperationsService;
use EruoFood\Dispatch\Application\Service\VehicleService;
use EruoFood\Dispatch\Domain\Assignment\Assignment;
use EruoFood\Dispatch\Domain\Offer\RiderOffer;
use EruoFood\Dispatch\Domain\Request\DispatchAttempt;
use EruoFood\Dispatch\Domain\Request\DispatchRequest;
use EruoFood\Dispatch\Domain\Vehicle\Vehicle;
use EruoFood\Dispatch\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Dispatch\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Control Centre's dispatch backend. **Backend only** — M26 builds no UI.
 *
 * ## Two permissions, doing two different jobs
 *
 * `dispatch.read` covers everything on this page that only *looks*: the queue,
 * active assignments, failures, history, health, fleet availability. Support
 * answering "where is my order?" needs all of it and should not need more.
 *
 * `dispatch.manage` covers everything that *changes who earns and who eats*:
 * manual assignment, forced reassignment, cancellation, vehicle approval. A
 * single `dispatch` permission would have handed the second set to everyone who
 * needed the first, which is most of the company.
 *
 * ## Every privileged action is audited
 *
 * Not "most". Manual assignment takes a delivery off one rider and gives it to
 * another; forced reassignment interrupts somebody mid-job; cancellation ends a
 * customer's order. Each writes to the append-only audit log with the actor,
 * the subject and the stated reason, *before* the response is returned — a
 * privileged action nobody can later attribute is one nobody can question.
 *
 * ## No coordinates on any of these endpoints
 *
 * The availability read counts riders; it does not list where they are. An
 * operations dashboard needs to know the fleet is thin, not to be a live map of
 * where a workforce is standing.
 */
final readonly class DispatchAdminController
{
    use ResolvesAuthUser;
    use RespondsWithData;

    public function __construct(
        private DispatchOperationsService $operations,
        private VehicleService $vehicles,
        private DispatchEngine $engine,
        private DispatchPresenter $presenter,
        private AuditService $audit,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Reads — dispatch.read
    |--------------------------------------------------------------------------
    */

    /** Deliveries still looking for a rider, longest wait first. */
    public function queue(Request $request): JsonResponse
    {
        $now = new DateTimeImmutable();

        return $this->collection(array_map(
            fn (DispatchRequest $r): array => $this->presenter->requestForOperator($r, $now),
            $this->operations->unassignedQueue($this->limit($request, 50, 200)),
        ));
    }

    /** Deliveries somebody is carrying right now. */
    public function active(Request $request): JsonResponse
    {
        return $this->collection(array_map(
            fn (Assignment $a): array => $this->presenter->assignmentForOperator($a),
            $this->operations->activeAssignments($this->limit($request, 100, 500)),
        ));
    }

    /** Searches that ended without a rider. */
    public function failures(Request $request): JsonResponse
    {
        $now = new DateTimeImmutable();

        return $this->collection(
            array_map(
                fn (DispatchRequest $r): array => $this->presenter->requestForOperator($r, $now),
                $this->operations->failures($this->limit($request, 50, 200)),
            ),
            ['reasons' => $this->operations->failureReasons()],
        );
    }

    /**
     * Everything tried for one delivery.
     *
     * The rejection breakdowns are the reason this endpoint exists: "eleven
     * riders nearby, nine stale locations" is a next action, and "no eligible
     * riders" is not.
     */
    public function history(string $requestId): JsonResponse
    {
        $history = $this->operations->history($requestId);
        $now = new DateTimeImmutable();

        return $this->data([
            'request' => $this->presenter->requestForOperator($history['request'], $now),
            'attempts' => array_map(
                fn (DispatchAttempt $a): array => $this->presenter->attemptForOperator($a),
                $history['attempts'],
            ),
            'offers' => array_map(
                static fn (RiderOffer $o): array => [
                    'id' => $o->id(),
                    'rider_id' => $o->riderId(),
                    'state' => $o->state()->value,
                    'score' => $o->score(),
                    // Operators see the score internals; riders do not. This is
                    // what makes the engine debuggable.
                    'score_breakdown' => $o->breakdown()?->toArray(),
                    'decline_reason' => $o->declineReason(),
                    'offered_at' => $o->offeredAt()->format(DATE_ATOM),
                    'responded_at' => $o->respondedAt()?->format(DATE_ATOM),
                ],
                $history['offers'],
            ),
        ]);
    }

    /** How much of the fleet could take work — counts, never positions. */
    public function availability(): JsonResponse
    {
        return $this->data($this->operations->riderAvailability());
    }

    /** Is dispatch healthy right now? */
    public function health(): JsonResponse
    {
        return $this->data($this->operations->health($this->engine->isEnabled()));
    }

    /** Vehicles waiting on an operator's decision, oldest first. */
    public function vehicleQueue(Request $request): JsonResponse
    {
        $queue = $this->vehicles->queue($this->limit($request, 50, 200), (int) $request->query('offset', '0'));
        $now = new DateTimeImmutable();

        return $this->collection(
            array_map(fn (Vehicle $v): array => $this->presenter->vehicle($v, $now), $queue['items']),
            ['total' => $queue['total']],
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Writes — dispatch.manage, audited every time
    |--------------------------------------------------------------------------
    */

    /** An operator puts a delivery on a specific rider. */
    public function assign(Request $httpRequest, string $requestId): JsonResponse
    {
        $data = $httpRequest->validate([
            'rider_id' => ['required', 'uuid'],
            // Required, not optional. An override with no stated reason is an
            // audit entry nobody can interpret six months later.
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $assignment = $this->operations->manuallyAssign($requestId, $data['rider_id']);

        $this->record($httpRequest, 'dispatch.manual_assignment', 'dispatch_request', $requestId, [
            'rider_id' => $data['rider_id'],
            'assignment_id' => $assignment->id(),
            'reason' => $data['reason'],
        ]);

        return $this->data($this->presenter->assignmentForOperator($assignment), 201);
    }

    /** An operator takes a delivery off the rider holding it. */
    public function reassign(Request $httpRequest, string $assignmentId): JsonResponse
    {
        $data = $httpRequest->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $replacement = $this->operations->forceReassign($assignmentId, $data['reason']);

        $this->record($httpRequest, 'dispatch.forced_reassignment', 'assignment', $assignmentId, [
            'reason' => $data['reason'],
            'replacement_request_id' => $replacement?->id(),
        ]);

        return $this->data([
            'replacement_request' => $replacement === null
                ? null
                : $this->presenter->requestForOperator($replacement, new DateTimeImmutable()),
            // Null means the delivery's time budget was too far spent to open a
            // fresh search. It needs a human, and saying so is more useful than
            // opening a search that fails in twenty seconds.
            'needs_manual_handling' => $replacement === null,
        ]);
    }

    /** An operator stops a search. */
    public function cancel(Request $httpRequest, string $requestId): JsonResponse
    {
        $data = $httpRequest->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $request = $this->operations->cancelRequest($requestId, $data['reason']);

        $this->record($httpRequest, 'dispatch.search_cancelled', 'dispatch_request', $requestId, [
            'reason' => $data['reason'],
        ]);

        return $this->data($this->presenter->requestForOperator($request, new DateTimeImmutable()));
    }

    /** An operator accepts a vehicle's paperwork. The only path to a dispatchable vehicle. */
    public function approveVehicle(Request $httpRequest, string $vehicleId): JsonResponse
    {
        $data = $httpRequest->validate([
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $vehicle = $this->vehicles->approve(
            $this->currentUserId($httpRequest),
            $vehicleId,
            $data['note'] ?? null,
        );

        $this->record($httpRequest, 'dispatch.vehicle_approved', 'vehicle', $vehicleId, [
            'rider_id' => $vehicle->riderId(),
            'type' => $vehicle->type()->value,
        ]);

        return $this->data($this->presenter->vehicle($vehicle, new DateTimeImmutable()));
    }

    public function rejectVehicle(Request $httpRequest, string $vehicleId): JsonResponse
    {
        $data = $httpRequest->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $vehicle = $this->vehicles->reject(
            $this->currentUserId($httpRequest),
            $vehicleId,
            $data['reason'],
        );

        $this->record($httpRequest, 'dispatch.vehicle_rejected', 'vehicle', $vehicleId, [
            'rider_id' => $vehicle->riderId(),
            'reason' => $data['reason'],
        ]);

        return $this->data($this->presenter->vehicle($vehicle, new DateTimeImmutable()));
    }

    /** Operations withdrawing a vehicle — an incident, a failed inspection. */
    public function suspendVehicle(Request $httpRequest, string $vehicleId): JsonResponse
    {
        $data = $httpRequest->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $vehicle = $this->vehicles->suspend($vehicleId, $data['reason']);

        $this->record($httpRequest, 'dispatch.vehicle_suspended', 'vehicle', $vehicleId, [
            'rider_id' => $vehicle->riderId(),
            'reason' => $data['reason'],
        ]);

        return $this->data($this->presenter->vehicle($vehicle, new DateTimeImmutable()));
    }

    /**
     * Write the entry before the response goes out.
     *
     * A privileged action nobody can later attribute is one nobody can
     * question, so this is not fire-and-forget: if the audit write fails, the
     * request fails, and the operator tries again.
     *
     * @param array<string, scalar|null> $context
     */
    private function record(
        Request $request,
        string $action,
        string $subjectType,
        string $subjectId,
        array $context,
    ): void {
        $this->audit->record(
            $this->currentUserId($request),
            AuditCategory::Operations,
            $action,
            $subjectType,
            $subjectId,
            $context,
            $request->ip(),
        );
    }

    private function limit(Request $request, int $default, int $max): int
    {
        return min($max, max(1, (int) $request->query('limit', (string) $default)));
    }
}
