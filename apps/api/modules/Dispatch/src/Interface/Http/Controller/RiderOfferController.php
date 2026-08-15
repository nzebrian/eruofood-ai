<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Interface\Http\Controller;

use DateTimeImmutable;
use EruoFood\Dispatch\Application\Service\AssignmentService;
use EruoFood\Dispatch\Application\Service\DeliveryProgressService;
use EruoFood\Dispatch\Domain\Enum\AssignmentState;
use EruoFood\Dispatch\Domain\Exception\DispatchInvalidState;
use EruoFood\Dispatch\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Dispatch\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What a rider does: see the offer, answer it, report where they have got to.
 *
 * ## No rider id crosses the wire, anywhere
 *
 * Every method resolves the rider from the authenticated account. Nothing here
 * accepts a rider id, a delivery id to claim, or a request id to assign
 * oneself. That is not a convenience — it is what makes rider self-assignment
 * structurally impossible rather than merely forbidden: an endpoint that cannot
 * express "assign me to that" cannot be tricked into doing it.
 *
 * ## No client-supplied location is trusted
 *
 * Nothing here takes coordinates. Dispatch decisions read the rider's position
 * from M25, where it was written under M25's own authorisation. A rider who
 * could post their own position to a dispatch endpoint could put themselves
 * outside every restaurant in Lagos at once.
 *
 * ## Ownership is checked against the record, not the URL
 *
 * An offer id and an assignment id are UUIDs in a path. The services check both
 * against the rider record, and refuse identically whether the thing belongs to
 * somebody else or does not exist — so this cannot be used to discover which
 * ids are real.
 */
final readonly class RiderOfferController
{
    use ResolvesAuthUser;
    use RespondsWithData;

    public function __construct(
        private AssignmentService $assignments,
        private DeliveryProgressService $progress,
        private DispatchPresenter $presenter,
    ) {
    }

    /** The offer currently on this rider's screen, if any. */
    public function current(Request $request): JsonResponse
    {
        $offer = $this->assignments->liveOfferFor($this->currentUserId($request));

        return $this->data([
            'offer' => $offer === null
                ? null
                : $this->presenter->offerForRider($offer, new DateTimeImmutable()),
        ]);
    }

    /** The rider takes it. */
    public function accept(Request $httpRequest, string $offerId): JsonResponse
    {
        $assignment = $this->assignments->accept($this->currentUserId($httpRequest), $offerId);

        return $this->data([
            'assignment' => $this->presenter->assignmentForRider(
                $assignment,
                $this->progress->nextStatesFor($assignment),
            ),
        ], 201);
    }

    /** The rider says no. */
    public function decline(Request $httpRequest, string $offerId): JsonResponse
    {
        $data = $httpRequest->validate([
            // Optional, and free text rather than a fixed list: a decline
            // reason is feedback, and forcing a rider to pick from a menu at
            // the moment they are declining produces menu-shaped answers.
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $offer = $this->assignments->decline(
            $this->currentUserId($httpRequest),
            $offerId,
            $data['reason'] ?? null,
        );

        return $this->data([
            'offer' => $this->presenter->offerForRider($offer, new DateTimeImmutable()),
        ]);
    }

    /** What this rider is carrying right now. */
    public function currentAssignment(Request $request): JsonResponse
    {
        $assignment = $this->assignments->currentFor($this->currentUserId($request));

        return $this->data([
            'assignment' => $assignment === null
                ? null
                : $this->presenter->assignmentForRider(
                    $assignment,
                    $this->progress->nextStatesFor($assignment),
                ),
        ]);
    }

    /**
     * The rider reports where they have got to.
     *
     * The validated list is the rider-drivable half of the state machine.
     * `accepted`, `cancelled` and `reassignment_required` are absent because
     * they are not a rider's to declare — accepting happens under the
     * assignment lock, and the other two are operational decisions.
     */
    public function advance(Request $httpRequest, string $assignmentId): JsonResponse
    {
        $data = $httpRequest->validate([
            'state' => [
                'required',
                'string',
                'in:en_route_pickup,arrived_pickup,picked_up,in_transit,delivered',
            ],
        ]);

        $next = AssignmentState::tryFrom($data['state'])
            ?? throw DispatchInvalidState::transition('unknown', (string) $data['state']);

        $assignment = $this->progress->advance(
            $this->currentUserId($httpRequest),
            $assignmentId,
            $next,
        );

        return $this->data([
            'assignment' => $this->presenter->assignmentForRider(
                $assignment,
                $this->progress->nextStatesFor($assignment),
            ),
        ]);
    }
}
