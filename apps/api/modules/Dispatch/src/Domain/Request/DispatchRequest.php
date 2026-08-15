<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Domain\Request;

use DateTimeImmutable;
use EruoFood\Dispatch\Domain\Enum\DispatchFailureReason;
use EruoFood\Dispatch\Domain\Enum\DispatchState;
use EruoFood\Dispatch\Domain\Enum\VehicleType;
use EruoFood\Dispatch\Domain\Exception\DispatchInvalidState;

/**
 * One delivery's search for a rider.
 *
 * ## What this is not
 *
 * It is not the delivery. Marketplace's `Delivery` remains the operational
 * delivery aggregate — M26 decision 1 — and this holds only the *search*:
 * where to collect, where to take it, what vehicle it needs, how long we have
 * been looking and how many riders we have asked. `deliveryId` is a soft
 * reference; nothing here duplicates a field Marketplace owns.
 *
 * ## Why it has a deadline
 *
 * `expiresAt` is set when the request is created, not recomputed as it goes.
 * Without a fixed deadline a request that keeps finding candidates can keep
 * trying indefinitely, and the customer is the one waiting. When the budget
 * runs out the request fails to operations, honestly, rather than cycling.
 *
 * ## Why the attempt counter lives here
 *
 * The counter and the state are one row, so "may this request be attempted
 * again?" is answered by one read under one lock rather than by counting rows
 * in another table while a second worker inserts into it.
 */
final class DispatchRequest
{
    private function __construct(
        private readonly string $id,
        private readonly string $deliveryId,
        private readonly string $orderId,
        private readonly string $vendorId,
        private readonly float $pickupLat,
        private readonly float $pickupLng,
        private readonly float $dropoffLat,
        private readonly float $dropoffLng,
        private readonly VehicleType $requiredVehicleType,
        private readonly ?int $loadKg,
        private readonly ?int $loadLitres,
        private readonly ?string $zoneId,
        private DispatchState $state,
        private int $attemptCount,
        private readonly int $maxAttempts,
        private ?string $assignedRiderId,
        private ?DateTimeImmutable $assignedAt,
        private ?DispatchFailureReason $failureReason,
        private ?DateTimeImmutable $failedAt,
        private readonly DateTimeImmutable $createdAt,
        private readonly DateTimeImmutable $expiresAt,
        private DateTimeImmutable $updatedAt,
        private int $version,
    ) {
    }

    public static function open(
        string $id,
        string $deliveryId,
        string $orderId,
        string $vendorId,
        float $pickupLat,
        float $pickupLng,
        float $dropoffLat,
        float $dropoffLng,
        DateTimeImmutable $now,
        int $maxAttempts,
        int $timeBudgetSeconds,
        VehicleType $requiredVehicleType = VehicleType::Bike,
        ?int $loadKg = null,
        ?int $loadLitres = null,
        ?string $zoneId = null,
    ): self {
        return new self(
            $id,
            $deliveryId,
            $orderId,
            $vendorId,
            $pickupLat,
            $pickupLng,
            $dropoffLat,
            $dropoffLng,
            $requiredVehicleType,
            $loadKg,
            $loadLitres,
            $zoneId,
            DispatchState::Pending,
            0,
            $maxAttempts,
            null,
            null,
            null,
            null,
            $now,
            $now->modify(sprintf('+%d seconds', $timeBudgetSeconds)),
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
            (string) $attributes['delivery_id'],
            (string) $attributes['order_id'],
            (string) $attributes['vendor_id'],
            (float) $attributes['pickup_lat'],
            (float) $attributes['pickup_lng'],
            (float) $attributes['dropoff_lat'],
            (float) $attributes['dropoff_lng'],
            VehicleType::from((string) $attributes['required_vehicle_type']),
            $attributes['load_kg'] === null ? null : (int) $attributes['load_kg'],
            $attributes['load_litres'] === null ? null : (int) $attributes['load_litres'],
            $attributes['zone_id'] === null ? null : (string) $attributes['zone_id'],
            DispatchState::from((string) $attributes['state']),
            (int) $attributes['attempt_count'],
            (int) $attributes['max_attempts'],
            $attributes['assigned_rider_id'] === null ? null : (string) $attributes['assigned_rider_id'],
            $attributes['assigned_at'] === null ? null : new DateTimeImmutable((string) $attributes['assigned_at']),
            $attributes['failure_reason'] === null
                ? null
                : DispatchFailureReason::from((string) $attributes['failure_reason']),
            $attributes['failed_at'] === null ? null : new DateTimeImmutable((string) $attributes['failed_at']),
            new DateTimeImmutable((string) $attributes['created_at']),
            new DateTimeImmutable((string) $attributes['expires_at']),
            new DateTimeImmutable((string) $attributes['updated_at']),
            (int) $attributes['version'],
        );
    }

    /**
     * A worker takes ownership before building a pool.
     *
     * Only `Pending` is claimable. A request another worker is already working
     * is exactly what a second worker must not pick up — two pools would be
     * built for one delivery and two riders would be offered it.
     */
    public function claim(DateTimeImmutable $now): void
    {
        if (! $this->state->isClaimable()) {
            throw DispatchInvalidState::transition($this->state->value, DispatchState::Dispatching->value);
        }

        $this->state = DispatchState::Dispatching;
        $this->touch($now);
    }

    /** Release a claim without consuming an attempt — a worker shutting down cleanly. */
    public function release(DateTimeImmutable $now): void
    {
        if ($this->state !== DispatchState::Dispatching) {
            return;
        }

        $this->state = DispatchState::Pending;
        $this->touch($now);
    }

    public function recordAttempt(DateTimeImmutable $now): void
    {
        $this->attemptCount++;
        $this->touch($now);
    }

    /**
     * Whether another round is worth starting.
     *
     * Both budgets, checked together. Attempts alone would let a request that
     * finds a rider every time run for an hour; time alone would let one that
     * finds nobody hammer the map provider until the clock ran out.
     */
    public function mayAttemptAgain(DateTimeImmutable $now): bool
    {
        return ! $this->state->isTerminal()
            && $this->attemptCount < $this->maxAttempts
            && ! $this->hasExpired($now);
    }

    public function hasExpired(DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }

    /**
     * A rider accepted.
     *
     * The transition the whole context exists to reach, and a terminal one:
     * this request is answered. A rider dropping out afterwards produces a new
     * request rather than reopening this one, so the record of what was tried
     * stays readable.
     */
    public function assign(string $riderId, DateTimeImmutable $now): void
    {
        if ($this->state->isTerminal()) {
            throw DispatchInvalidState::transition($this->state->value, DispatchState::Assigned->value);
        }

        $this->state = DispatchState::Assigned;
        $this->assignedRiderId = $riderId;
        $this->assignedAt = $now;
        $this->touch($now);
    }

    public function fail(DispatchFailureReason $reason, DateTimeImmutable $now): void
    {
        if ($this->state->isTerminal()) {
            throw DispatchInvalidState::transition($this->state->value, DispatchState::Failed->value);
        }

        $this->state = DispatchState::Failed;
        $this->failureReason = $reason;
        $this->failedAt = $now;
        $this->touch($now);
    }

    /** The order was cancelled, or an operator stopped the search. */
    public function cancel(DateTimeImmutable $now): void
    {
        if ($this->state->isTerminal()) {
            throw DispatchInvalidState::transition($this->state->value, DispatchState::Cancelled->value);
        }

        $this->state = DispatchState::Cancelled;
        $this->failureReason = DispatchFailureReason::Cancelled;
        $this->failedAt = $now;
        $this->touch($now);
    }

    /** Seconds spent looking — the number that tells operations whether dispatch is healthy. */
    public function elapsedSeconds(DateTimeImmutable $now): int
    {
        return max(0, $now->getTimestamp() - $this->createdAt->getTimestamp());
    }

    private function touch(DateTimeImmutable $now): void
    {
        $this->updatedAt = $now;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function deliveryId(): string
    {
        return $this->deliveryId;
    }

    public function orderId(): string
    {
        return $this->orderId;
    }

    public function vendorId(): string
    {
        return $this->vendorId;
    }

    public function pickupLat(): float
    {
        return $this->pickupLat;
    }

    public function pickupLng(): float
    {
        return $this->pickupLng;
    }

    public function dropoffLat(): float
    {
        return $this->dropoffLat;
    }

    public function dropoffLng(): float
    {
        return $this->dropoffLng;
    }

    public function requiredVehicleType(): VehicleType
    {
        return $this->requiredVehicleType;
    }

    public function loadKg(): ?int
    {
        return $this->loadKg;
    }

    public function loadLitres(): ?int
    {
        return $this->loadLitres;
    }

    public function zoneId(): ?string
    {
        return $this->zoneId;
    }

    public function state(): DispatchState
    {
        return $this->state;
    }

    public function attemptCount(): int
    {
        return $this->attemptCount;
    }

    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function assignedRiderId(): ?string
    {
        return $this->assignedRiderId;
    }

    public function assignedAt(): ?DateTimeImmutable
    {
        return $this->assignedAt;
    }

    public function failureReason(): ?DispatchFailureReason
    {
        return $this->failureReason;
    }

    public function failedAt(): ?DateTimeImmutable
    {
        return $this->failedAt;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
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
