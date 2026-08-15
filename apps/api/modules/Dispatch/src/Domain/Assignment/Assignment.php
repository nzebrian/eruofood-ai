<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Domain\Assignment;

use DateTimeImmutable;
use EruoFood\Dispatch\Domain\Enum\AssignmentState;
use EruoFood\Dispatch\Domain\Exception\DispatchInvalidState;

/**
 * A rider is carrying this delivery.
 *
 * ## The boundary with Marketplace (M26 decision 1)
 *
 * Marketplace's `Delivery` stays the operational delivery aggregate and owns
 * the *journey*. This owns the *relationship* — who accepted, and whether it
 * still holds. Two state machines over one real process can contradict each
 * other, so the rule is one-directional and narrow, and it lives in
 * {@see AssignmentState}:
 *
 * - Dispatch is authoritative from `Accepted` until the rider starts moving.
 * - Once the journey begins, Marketplace leads and this mirrors each advance
 *   through {@see mirrorDeliveryStatus()} — a projection of a decision already
 *   made, never a second place the journey can be driven from.
 * - Only cancellation and reassignment may be entered from the Dispatch side
 *   once the journey has begun, and both end the assignment.
 *
 * ## Why it is never deleted
 *
 * A rider who drops out leaves a terminal assignment behind and a *new* request
 * opens. Deleting it would erase the fact that a rider was assigned and did not
 * finish, which is exactly what an operator investigating a late order needs to
 * see — and what the fairness and performance history is counted from.
 */
final class Assignment
{
    private function __construct(
        private readonly string $id,
        private readonly string $requestId,
        private readonly string $offerId,
        private readonly string $deliveryId,
        private readonly string $riderId,
        private readonly ?string $vehicleId,
        private AssignmentState $state,
        private readonly ?int $etaSeconds,
        private ?string $endedReason,
        private ?DateTimeImmutable $endedAt,
        private readonly DateTimeImmutable $acceptedAt,
        private DateTimeImmutable $updatedAt,
        private int $version,
    ) {
    }

    public static function accept(
        string $id,
        string $requestId,
        string $offerId,
        string $deliveryId,
        string $riderId,
        DateTimeImmutable $now,
        ?string $vehicleId = null,
        ?int $etaSeconds = null,
    ): self {
        return new self(
            $id,
            $requestId,
            $offerId,
            $deliveryId,
            $riderId,
            $vehicleId,
            AssignmentState::Accepted,
            $etaSeconds,
            null,
            null,
            $now,
            $now,
            1,
        );
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function reconstitute(array $attributes): self
    {
        return new self(
            (string) $attributes['id'],
            (string) $attributes['request_id'],
            (string) $attributes['offer_id'],
            (string) $attributes['delivery_id'],
            (string) $attributes['rider_id'],
            $attributes['vehicle_id'] === null ? null : (string) $attributes['vehicle_id'],
            AssignmentState::from((string) $attributes['state']),
            $attributes['eta_seconds'] === null ? null : (int) $attributes['eta_seconds'],
            $attributes['ended_reason'] === null ? null : (string) $attributes['ended_reason'],
            $attributes['ended_at'] === null ? null : new DateTimeImmutable((string) $attributes['ended_at']),
            new DateTimeImmutable((string) $attributes['accepted_at']),
            new DateTimeImmutable((string) $attributes['updated_at']),
            (int) $attributes['version'],
        );
    }

    /**
     * Advance to the next state, refusing anything the table does not allow.
     *
     * Written against {@see AssignmentState::allowedNext()} rather than an
     * ordinal comparison: the pre-M26 `Delivery` used a `+1` ordinal table whose
     * `en_route` sat *after* `picked_up`, the opposite of `EN_ROUTE_PICKUP`. An
     * explicit table cannot be wrong quietly.
     */
    public function advanceTo(AssignmentState $next, DateTimeImmutable $now, ?string $reason = null): void
    {
        if (! $this->state->canTransitionTo($next)) {
            throw DispatchInvalidState::transition($this->state->value, $next->value);
        }

        $this->state = $next;

        if ($next->isTerminal()) {
            $this->endedReason = $reason;
            $this->endedAt = $now;
        }

        $this->updatedAt = $now;
    }

    /**
     * Mirror an advance Marketplace has already made.
     *
     * The one-way bridge. Returns false — rather than throwing — when there is
     * nothing to mirror or the mirror would go backwards, because Marketplace
     * is the authority here and Dispatch refusing its decision would be the
     * contradiction this design exists to prevent. A delivery status with no
     * assignment meaning simply leaves the record alone.
     */
    public function mirrorDeliveryStatus(string $deliveryStatus, DateTimeImmutable $now): bool
    {
        $target = AssignmentState::forDeliveryStatus($deliveryStatus);

        if ($target === null || $target === $this->state || $this->state->isTerminal()) {
            return false;
        }

        if (! $this->state->canTransitionTo($target)) {
            return false;
        }

        $this->state = $target;

        if ($target->isTerminal()) {
            $this->endedAt = $now;
            $this->endedReason = 'delivered';
        }

        $this->updatedAt = $now;

        return true;
    }

    /**
     * The rider is out and somebody else must take it.
     *
     * Refused once the food is in their bag — past pickup this is an
     * operational incident, not a dispatch decision, and
     * {@see AssignmentState::allowedNext()} enforces that.
     */
    public function requireReassignment(string $reason, DateTimeImmutable $now): void
    {
        $this->advanceTo(AssignmentState::ReassignmentRequired, $now, $reason);
    }

    public function cancel(string $reason, DateTimeImmutable $now): void
    {
        $this->advanceTo(AssignmentState::Cancelled, $now, $reason);
    }

    public function belongsTo(string $riderId): bool
    {
        return $this->riderId === $riderId;
    }

    /** Still occupying this rider and this delivery — mirrors the partial unique indexes. */
    public function isActive(): bool
    {
        return $this->state->isActive();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function requestId(): string
    {
        return $this->requestId;
    }

    public function offerId(): string
    {
        return $this->offerId;
    }

    public function deliveryId(): string
    {
        return $this->deliveryId;
    }

    public function riderId(): string
    {
        return $this->riderId;
    }

    public function vehicleId(): ?string
    {
        return $this->vehicleId;
    }

    public function state(): AssignmentState
    {
        return $this->state;
    }

    public function etaSeconds(): ?int
    {
        return $this->etaSeconds;
    }

    public function endedReason(): ?string
    {
        return $this->endedReason;
    }

    public function endedAt(): ?DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function acceptedAt(): DateTimeImmutable
    {
        return $this->acceptedAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function version(): int
    {
        return $this->version;
    }
}
