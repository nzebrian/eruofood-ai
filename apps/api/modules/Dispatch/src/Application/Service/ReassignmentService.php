<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Application\Service;

use EruoFood\Dispatch\Application\Port\DeliveryLifecycle;
use EruoFood\Dispatch\Domain\Assignment\Assignment;
use EruoFood\Dispatch\Domain\Assignment\AssignmentRepository;
use EruoFood\Dispatch\Domain\Enum\AssignmentState;
use EruoFood\Dispatch\Domain\Event\ReassignmentRequired;
use EruoFood\Dispatch\Domain\Exception\DispatchInvalidState;
use EruoFood\Dispatch\Domain\Exception\DispatchNotFound;
use EruoFood\Dispatch\Domain\Request\DispatchRequest;
use EruoFood\Dispatch\Domain\Request\DispatchRequestRepository;
use EruoFood\Shared\Domain\Clock;
use EruoFood\Shared\Domain\EventBus;
use EruoFood\Shared\Domain\TransactionManager;

/**
 * A rider is out and the delivery needs somebody else.
 *
 * ## A new request, not a reopened one
 *
 * The old request stays terminal and a fresh one opens. Reopening would erase
 * the record of what was already tried — which rider, which radius, how many
 * riders were rejected and why — and that record is exactly what an operator
 * needs when a customer rings about a late order.
 *
 * It also keeps the partial unique index honest: "one live search per delivery"
 * is only meaningful while a finished search stays finished.
 *
 * ## The remaining time budget carries over
 *
 * The new request inherits what is left of the original deadline rather than
 * starting a fresh ten minutes. The customer has already been waiting; a rider
 * dropping out at minute nine must not buy the platform another ten minutes of
 * their patience.
 *
 * ## Past pickup, this is not a dispatch decision
 *
 * Once the rider has the food, reassignment is an operational incident with a
 * meal in somebody's bag. {@see AssignmentState::allowedNext()} refuses it, and
 * that refusal surfaces here as an invalid-state error rather than being
 * quietly worked around.
 */
final readonly class ReassignmentService
{
    public function __construct(
        private AssignmentRepository $assignments,
        private DispatchRequestRepository $requests,
        private DeliveryLifecycle $deliveries,
        private TransactionManager $transactions,
        private EventBus $events,
        private Clock $clock,
        private int $minimumBudgetSeconds,
    ) {
    }

    /**
     * Release the rider and open a fresh search.
     *
     * @return DispatchRequest|null the new search, or null if the delivery has
     *                              exhausted its budget and belongs to operations now
     */
    public function reassign(string $assignmentId, string $reason): ?DispatchRequest
    {
        return $this->transactions->atomic(function () use ($assignmentId, $reason): ?DispatchRequest {
            $assignment = $this->assignments->find($assignmentId)
                ?? throw DispatchNotFound::of('assignment', $assignmentId);

            $now = $this->clock->now();

            if (! $assignment->state()->canTransitionTo(AssignmentState::ReassignmentRequired)) {
                throw DispatchInvalidState::transition(
                    $assignment->state()->value,
                    AssignmentState::ReassignmentRequired->value,
                );
            }

            $original = $this->requests->find($assignment->requestId())
                ?? throw DispatchNotFound::of('dispatch request', $assignment->requestId());

            $assignment->requireReassignment($reason, $now);
            $this->assignments->save($assignment);

            // The delivery goes back to looking and stops claiming a rider who
            // is not coming. Leaving the old rider id on it is how a customer
            // gets told somebody is on the way for another ten minutes.
            $this->deliveries->releaseRider($assignment->deliveryId());

            $this->events->publish(new ReassignmentRequired(
                $assignment->id(),
                $assignment->deliveryId(),
                $assignment->riderId(),
                $reason,
                $now,
            ));

            $remaining = $original->expiresAt()->getTimestamp() - $now->getTimestamp();

            if ($remaining < $this->minimumBudgetSeconds) {
                // Not enough time left to plausibly find anybody. Opening a
                // search that will fail in twenty seconds wastes the rider
                // pool's attention and delays the honest answer the customer
                // needs, which is that this one needs a human.
                return null;
            }

            $replacement = DispatchRequest::open(
                id: $this->requests->nextIdentity(),
                deliveryId: $original->deliveryId(),
                orderId: $original->orderId(),
                vendorId: $original->vendorId(),
                pickupLat: $original->pickupLat(),
                pickupLng: $original->pickupLng(),
                dropoffLat: $original->dropoffLat(),
                dropoffLng: $original->dropoffLng(),
                now: $now,
                maxAttempts: $original->maxAttempts(),
                // What is left of the customer's patience, not a fresh grant.
                timeBudgetSeconds: $remaining,
                requiredVehicleType: $original->requiredVehicleType(),
                loadKg: $original->loadKg(),
                loadLitres: $original->loadLitres(),
                zoneId: $original->zoneId(),
            );

            $this->requests->save($replacement);

            return $replacement;
        });
    }

    /** The rider hands the job back before setting off. */
    public function releasedByRider(Assignment $assignment, string $reason): ?DispatchRequest
    {
        return $this->reassign($assignment->id(), $reason);
    }
}
