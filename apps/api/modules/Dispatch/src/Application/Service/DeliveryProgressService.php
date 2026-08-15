<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Application\Service;

use EruoFood\Dispatch\Application\Port\DeliveryLifecycle;
use EruoFood\Dispatch\Application\Port\RiderDirectory;
use EruoFood\Dispatch\Domain\Assignment\Assignment;
use EruoFood\Dispatch\Domain\Assignment\AssignmentRepository;
use EruoFood\Dispatch\Domain\Enum\AssignmentState;
use EruoFood\Dispatch\Domain\Exception\DispatchInvalidState;
use EruoFood\Dispatch\Domain\Exception\DispatchNotAuthorized;
use EruoFood\Dispatch\Domain\Exception\DispatchNotFound;
use EruoFood\Shared\Domain\Clock;
use EruoFood\Shared\Domain\TransactionManager;

/**
 * The rider moving through the journey — and the only place it can be moved.
 *
 * ## Why this exists instead of a method on AssignmentService
 *
 * M26 decision 1 says Marketplace leads once the journey begins and Dispatch
 * mirrors. That is easy to state and easy to violate: any code that advances
 * the Dispatch assignment and then tells Marketplace has quietly made Dispatch
 * the authority. So there is exactly one entry point, and its order is fixed:
 *
 * 1. **Marketplace advances the delivery.** If it refuses, nothing else
 *    happens and the rider is told — the delivery's own transition table is
 *    what decides whether a move is legal.
 * 2. **Dispatch mirrors the outcome** into its assignment.
 *
 * Both inside one transaction, so the two records cannot end up disagreeing
 * because the second write failed.
 *
 * ## The mirror can decline silently
 *
 * If the assignment cannot take the mirrored state — a delivery advanced by an
 * operator through Marketplace's own endpoint while the assignment was
 * cancelled, say — the delivery still moves and the mirror does not. That is
 * the correct asymmetry: Marketplace is authoritative, and Dispatch refusing
 * its decision would be the contradiction this design exists to prevent.
 */
final readonly class DeliveryProgressService
{
    /**
     * The journey states a rider may drive, in order.
     *
     * `Accepted` is absent because acceptance happens through
     * {@see AssignmentService::accept()} under the assignment lock; `Delivered`
     * is present and terminal. Nothing here lets a rider create or reassign
     * their own work — see {@see \EruoFood\Dispatch\Application\Service\ReassignmentService}.
     */
    private const RIDER_DRIVEN = [
        AssignmentState::EnRoutePickup,
        AssignmentState::ArrivedPickup,
        AssignmentState::PickedUp,
        AssignmentState::InTransit,
        AssignmentState::Delivered,
    ];

    public function __construct(
        private AssignmentRepository $assignments,
        private DeliveryLifecycle $deliveries,
        private RiderDirectory $riders,
        private TransactionManager $transactions,
        private Clock $clock,
    ) {
    }

    /**
     * A rider reports where they have got to.
     *
     * @param string $userId the authenticated account, checked against the assignment
     */
    public function advance(string $userId, string $assignmentId, AssignmentState $next): Assignment
    {
        $riderId = $this->riders->riderIdFor($userId)
            ?? throw DispatchNotFound::of('rider', $userId);

        return $this->transactions->atomic(function () use ($riderId, $assignmentId, $next): Assignment {
            $assignment = $this->assignments->find($assignmentId)
                ?? throw DispatchNotFound::of('assignment', $assignmentId);

            if (! $assignment->belongsTo($riderId)) {
                // Same refusal whether it belongs to somebody else or does not
                // exist, so this cannot be used to enumerate live deliveries.
                throw new DispatchNotAuthorized('This delivery is not assigned to you.');
            }

            if (! in_array($next, self::RIDER_DRIVEN, true)) {
                throw DispatchInvalidState::transition($assignment->state()->value, $next->value);
            }

            // Checked here so the rider gets Dispatch's specific error rather
            // than a bare "the delivery refused it" — but Marketplace still has
            // the final say below.
            if (! $assignment->state()->canTransitionTo($next)) {
                throw DispatchInvalidState::transition($assignment->state()->value, $next->value);
            }

            // Marketplace first. It owns the journey.
            if (! $this->deliveries->advance($assignment->deliveryId(), $next->value)) {
                throw DispatchInvalidState::transition($assignment->state()->value, $next->value);
            }

            // Then the mirror, which is a projection of the decision just made.
            $now = $this->clock->now();

            if ($assignment->mirrorDeliveryStatus($next->value, $now)) {
                $this->assignments->save($assignment);
            }

            return $assignment;
        });
    }

    /**
     * The states this rider may move to right now — what the app's next button offers.
     *
     * @return list<AssignmentState>
     */
    public function nextStatesFor(Assignment $assignment): array
    {
        return array_values(array_filter(
            $assignment->state()->allowedNext(),
            static fn (AssignmentState $state): bool => in_array($state, self::RIDER_DRIVEN, true),
        ));
    }
}
