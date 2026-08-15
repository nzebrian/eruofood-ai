<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Application\Service;

use DateTimeImmutable;
use EruoFood\Dispatch\Application\Port\CandidateSource;
use EruoFood\Dispatch\Application\Port\DeliveryLifecycle;
use EruoFood\Dispatch\Application\Port\RiderDirectory;
use EruoFood\Dispatch\Domain\Assignment\Assignment;
use EruoFood\Dispatch\Domain\Assignment\AssignmentRepository;
use EruoFood\Dispatch\Domain\Event\DeliveryAssigned;
use EruoFood\Dispatch\Domain\Event\OfferDeclined;
use EruoFood\Dispatch\Domain\Exception\AssignmentConflict;
use EruoFood\Dispatch\Domain\Exception\DispatchNotAuthorized;
use EruoFood\Dispatch\Domain\Exception\DispatchNotFound;
use EruoFood\Dispatch\Domain\Exception\RiderNoLongerEligible;
use EruoFood\Dispatch\Domain\Offer\OfferRepository;
use EruoFood\Dispatch\Domain\Offer\RiderOffer;
use EruoFood\Dispatch\Domain\Request\DispatchRequest;
use EruoFood\Dispatch\Domain\Request\DispatchRequestRepository;
use EruoFood\Shared\Domain\Clock;
use EruoFood\Shared\Domain\EventBus;
use EruoFood\Shared\Domain\Idempotency\IdempotencyStore;
use EruoFood\Shared\Domain\TransactionManager;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * A rider taps Accept. Exactly one of them wins.
 *
 * ## The failure this whole class is shaped around
 *
 * Two riders are offered the same delivery and both accept within the same
 * hundred milliseconds. If both succeed, two riders arrive at one restaurant
 * for one bag of food: the platform pays twice, the merchant is embarrassed,
 * and one rider did the work for nothing. It is the single worst thing this
 * context can do, and it is a completely ordinary race.
 *
 * ## Four layers, doing four different jobs
 *
 * 1. **`SELECT … FOR UPDATE` on the request**, taken first and always in that
 *    order, so the second rider's transaction waits rather than reading a stale
 *    "still looking" and proceeding on it.
 * 2. **The request's own state**, re-read under that lock. If it already says
 *    `assigned`, the second rider is told so — this is the check that actually
 *    fires in the common race, and the layers below it are for the cases where
 *    it does not.
 * 3. **Optimistic versions** on the offer and the request, so a writer that got
 *    past the lock somehow still cannot silently overwrite the winner.
 * 4. **The partial unique indexes** on `dispatch_assignments`. The last line,
 *    and the only one that still holds when a future refactor adds a second
 *    acceptance path and forgets the lock. A violation here is caught and
 *    turned into an honest "somebody else got there first", never a 500.
 *
 * Layer 4 is not redundant with layers 1–3: 1–3 are application discipline, one
 * refactor away from being bypassed. An index is not.
 *
 * ## Idempotent by rider, not by request
 *
 * A rider double-tapping Accept, or their phone retrying on a flaky connection,
 * must get the same assignment back — not a conflict, and certainly not two
 * assignments. That is keyed on the offer, because the offer is what they
 * answered.
 */
final readonly class AssignmentService
{
    public function __construct(
        private OfferRepository $offers,
        private AssignmentRepository $assignments,
        private DispatchRequestRepository $requests,
        private RiderDirectory $riders,
        private CandidateSource $candidates,
        private EligibilityService $eligibility,
        private DeliveryLifecycle $deliveries,
        private TransactionManager $transactions,
        private IdempotencyStore $idempotency,
        private EventBus $events,
        private Clock $clock,
    ) {
    }

    /**
     * The rider takes the job.
     *
     * @param string $userId the authenticated account, checked against the offer's rider
     */
    public function accept(string $userId, string $offerId): Assignment
    {
        $riderId = $this->riderIdOf($userId);

        // Replaying the same acceptance returns the same assignment rather than
        // a conflict: a phone retrying on a flaky connection is not a second
        // rider. Keyed on the offer and the rider, because the offer is what
        // they answered.
        $result = $this->idempotency->execute(
            'dispatch.accept',
            $offerId.':'.$riderId,
            hash('sha256', $offerId.'|'.$riderId),
            fn (): array => ['assignment_id' => $this->doAccept($riderId, $offerId)->id()],
        );

        $assignmentId = (string) ($result->value['assignment_id'] ?? '');

        return $this->assignments->find($assignmentId)
            ?? throw DispatchNotFound::of('assignment', $assignmentId);
    }

    private function doAccept(string $riderId, string $offerId): Assignment
    {
        return $this->transactions->atomic(function () use ($riderId, $offerId): Assignment {
            $offer = $this->offers->find($offerId) ?? throw DispatchNotFound::of('offer', $offerId);

            if (! $offer->belongsTo($riderId)) {
                // Same refusal whether the offer belongs to someone else or does
                // not exist, so this cannot be used to enumerate live offers.
                throw new DispatchNotAuthorized('This offer was not made to you.');
            }

            // Lock the request *first*, always. A consistent lock order is what
            // stops two acceptances deadlocking against each other; taking the
            // offer's lock first here and the request's first elsewhere is the
            // classic way to build one.
            $request = $this->requests->lockForUpdate($offer->requestId())
                ?? throw DispatchNotFound::of('dispatch request', $offer->requestId());

            $lockedOffer = $this->offers->lockForUpdate($offerId) ?? $offer;

            // Already answered by somebody. This is the check that fires in the
            // ordinary race, and it fires with the truth rather than a guess,
            // because the row was read under the lock.
            if ($request->state()->isTerminal()) {
                throw AssignmentConflict::deliveryTaken($request->deliveryId());
            }

            $now = $this->clock->now();

            // Throws if the offer was declined, expired or cancelled — and
            // distinguishes "you already answered" from "it expired", because a
            // rider tapping Accept a second after the sweep ran deserves the
            // truthful reason.
            $lockedOffer->accept($now);

            if ($this->assignments->activeForRider($riderId) !== null) {
                throw AssignmentConflict::riderTaken($riderId);
            }

            /*
            | Eligibility, re-checked here and not before.
            |
            | Seconds pass between an offer being made and a rider tapping
            | Accept. In that window a vehicle's insurance can lapse, an
            | operator can suspend a rider, M24 can revoke a verification.
            | Eligibility decided at offer time is a statement about the past.
            |
            | It happens *inside the lock* because outside it the answer could
            | change between the check and the write — which is the entire class
            | of bug this transaction exists to prevent. And it is the narrower
            | acceptance chain: refusing a rider at the moment they tap Accept
            | for a fairness reason would be refusing them for something that
            | has nothing to do with them.
            */
            $this->assertStillEligible($riderId, $request, $now);

            $assignment = Assignment::accept(
                id: $this->assignments->nextIdentity(),
                requestId: $request->id(),
                offerId: $lockedOffer->id(),
                deliveryId: $lockedOffer->deliveryId(),
                riderId: $riderId,
                now: $now,
                vehicleId: $lockedOffer->vehicleId(),
                etaSeconds: $lockedOffer->etaSeconds(),
            );

            try {
                $this->assignments->save($assignment);
            } catch (UniqueConstraintViolationException) {
                // Layer four. Reached only if the layers above were bypassed —
                // which is exactly the case they cannot cover themselves.
                throw AssignmentConflict::deliveryTaken($lockedOffer->deliveryId());
            }

            $this->offers->save($lockedOffer);

            $request->assign($riderId, $now);
            $this->requests->save($request);

            // Marketplace owns the delivery (M26 decision 1). Recording the
            // acceptance inside this transaction is what stops a delivery
            // claiming a rider whose assignment was rolled back — the two
            // records commit together or not at all.
            $this->deliveries->riderAccepted($lockedOffer->deliveryId(), $riderId);

            // Withdraw the others. A rider staring at an offer that was won
            // thirty seconds ago will tap it and be refused, which reads as the
            // app being broken.
            $this->withdrawOtherOffers($request->id(), $lockedOffer->id(), $now);

            $this->events->publish(new DeliveryAssigned(
                $assignment->id(),
                $request->id(),
                $lockedOffer->deliveryId(),
                $riderId,
                $lockedOffer->vehicleId(),
                $now,
            ));

            return $assignment;
        });
    }

    /** The rider says no. */
    public function decline(string $userId, string $offerId, ?string $reason = null): RiderOffer
    {
        $riderId = $this->riderIdOf($userId);

        return $this->transactions->atomic(function () use ($riderId, $offerId, $reason): RiderOffer {
            $offer = $this->offers->lockForUpdate($offerId) ?? throw DispatchNotFound::of('offer', $offerId);

            if (! $offer->belongsTo($riderId)) {
                throw new DispatchNotAuthorized('This offer was not made to you.');
            }

            $now = $this->clock->now();
            $offer->decline($now, $reason);
            $this->offers->save($offer);

            // Declining does not fail the request. It frees the rider and lets
            // the next round find somebody else — which is why the event says
            // what happened rather than instructing anybody what to do.
            $this->events->publish(new OfferDeclined(
                $offer->id(),
                $offer->requestId(),
                $riderId,
                $reason,
                $now,
            ));

            return $offer;
        });
    }

    /** What this rider is carrying right now, if anything. */
    public function currentFor(string $userId): ?Assignment
    {
        return $this->assignments->activeForRider($this->riderIdOf($userId));
    }

    /** The offer this rider is looking at, if any. */
    public function liveOfferFor(string $userId): ?RiderOffer
    {
        return $this->offers->liveForRider($this->riderIdOf($userId));
    }

    /**
     * Refuse the acceptance if the rider is no longer safe or permitted to do it.
     *
     * A rider whose position cannot be found at all is *not* refused: dispatch
     * chose them on a position that existed, and a phone that has lost signal
     * between the offer and the tap is not a safety problem. Refusing here
     * would take work from riders for a network outage.
     */
    private function assertStillEligible(string $riderId, DispatchRequest $request, DateTimeImmutable $now): void
    {
        $candidate = $this->candidates->forRider($riderId, $request, $now);

        if ($candidate === null) {
            return;
        }

        $reason = $this->eligibility->acceptanceReasonAgainst($candidate, $request, $now);

        if ($reason !== null) {
            throw RiderNoLongerEligible::because($reason);
        }
    }

    private function withdrawOtherOffers(string $requestId, string $winningOfferId, DateTimeImmutable $now): void
    {
        foreach ($this->offers->liveForRequest($requestId) as $other) {
            if ($other->id() === $winningOfferId) {
                continue;
            }

            $other->cancel($now);
            $this->offers->save($other);
        }
    }

    private function riderIdOf(string $userId): string
    {
        return $this->riders->riderIdFor($userId) ?? throw DispatchNotFound::of('rider', $userId);
    }
}
