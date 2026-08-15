<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Domain\Offer;

use DateTimeImmutable;
use EruoFood\Dispatch\Domain\Enum\OfferState;
use EruoFood\Dispatch\Domain\Exception\OfferNoLongerAnswerable;
use EruoFood\Dispatch\Domain\Scoring\ScoreBreakdown;

/**
 * One delivery, offered to one rider, for a short time.
 *
 * ## The expiry is written down, not calculated on read
 *
 * `expiresAt` is stamped when the offer is made. That matters because two
 * different processes decide whether it has run out — the rider's phone tapping
 * Accept, and the sweep looking for stale offers — and if they computed it
 * separately from a TTL they could disagree by milliseconds. One stored instant
 * means both read the same answer.
 *
 * ## Answering is one-shot
 *
 * `Offered` is the only non-terminal state, which is what makes the partial
 * unique index on `(rider_id) WHERE state = 'offered'` meaningful: a rider is
 * looking at exactly one offer at a time. {@see accept()} and {@see decline()}
 * both refuse a terminal offer, so a double-tap, a retried request, or a rider
 * accepting at the same instant the sweep expires them resolves to exactly one
 * outcome.
 *
 * The score breakdown travels with the offer because a scoring decision nobody
 * can explain afterwards is one nobody can debug, and one no rider can be given
 * an honest answer about.
 */
final class RiderOffer
{
    private function __construct(
        private readonly string $id,
        private readonly string $requestId,
        private readonly string $riderId,
        private readonly string $deliveryId,
        private readonly ?string $vehicleId,
        private readonly float $score,
        private readonly ?ScoreBreakdown $breakdown,
        private readonly ?int $etaSeconds,
        private readonly ?int $distanceMetres,
        private OfferState $state,
        private ?DateTimeImmutable $respondedAt,
        private ?string $declineReason,
        private readonly DateTimeImmutable $offeredAt,
        private readonly DateTimeImmutable $expiresAt,
        private int $version,
    ) {
    }

    public static function make(
        string $id,
        string $requestId,
        string $riderId,
        string $deliveryId,
        DateTimeImmutable $now,
        int $ttlSeconds,
        ?string $vehicleId = null,
        float $score = 0.0,
        ?ScoreBreakdown $breakdown = null,
        ?int $etaSeconds = null,
        ?int $distanceMetres = null,
    ): self {
        return new self(
            $id,
            $requestId,
            $riderId,
            $deliveryId,
            $vehicleId,
            $score,
            $breakdown,
            $etaSeconds,
            $distanceMetres,
            OfferState::Offered,
            null,
            null,
            $now,
            $now->modify(sprintf('+%d seconds', max(1, $ttlSeconds))),
            1,
        );
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function reconstitute(array $attributes, ?ScoreBreakdown $breakdown = null): self
    {
        return new self(
            (string) $attributes['id'],
            (string) $attributes['request_id'],
            (string) $attributes['rider_id'],
            (string) $attributes['delivery_id'],
            $attributes['vehicle_id'] === null ? null : (string) $attributes['vehicle_id'],
            (float) $attributes['score'],
            $breakdown,
            $attributes['eta_seconds'] === null ? null : (int) $attributes['eta_seconds'],
            $attributes['distance_metres'] === null ? null : (int) $attributes['distance_metres'],
            OfferState::from((string) $attributes['state']),
            $attributes['responded_at'] === null ? null : new DateTimeImmutable((string) $attributes['responded_at']),
            $attributes['decline_reason'] === null ? null : (string) $attributes['decline_reason'],
            new DateTimeImmutable((string) $attributes['offered_at']),
            new DateTimeImmutable((string) $attributes['expires_at']),
            (int) $attributes['version'],
        );
    }

    /**
     * The rider takes it.
     *
     * Refuses if the offer has already been answered *or* if the deadline has
     * passed, and the two are checked separately on purpose: a rider tapping
     * Accept one second after the sweep ran must be told the offer expired, not
     * that they already answered it.
     */
    public function accept(DateTimeImmutable $now): void
    {
        $this->assertAnswerable($now);

        $this->state = OfferState::Accepted;
        $this->respondedAt = $now;
        $this->touch();
    }

    public function decline(DateTimeImmutable $now, ?string $reason = null): void
    {
        $this->assertAnswerable($now);

        $this->state = OfferState::Declined;
        $this->respondedAt = $now;
        $this->declineReason = $reason;
        $this->touch();
    }

    /** The TTL ran out with no answer. */
    public function expire(DateTimeImmutable $now): void
    {
        if ($this->state->isTerminal()) {
            return;
        }

        $this->state = OfferState::Expired;
        $this->respondedAt = $now;
        $this->touch();
    }

    /** Withdrawn by the platform — the order was cancelled, or the rider went offline. */
    public function cancel(DateTimeImmutable $now): void
    {
        if ($this->state->isTerminal()) {
            return;
        }

        $this->state = OfferState::Cancelled;
        $this->respondedAt = $now;
        $this->touch();
    }

    public function hasExpired(DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }

    /** Whether this offer may still be answered right now. */
    public function isAnswerable(DateTimeImmutable $now): bool
    {
        return $this->state->isAnswerable() && ! $this->hasExpired($now);
    }

    public function secondsRemaining(DateTimeImmutable $now): int
    {
        return max(0, $this->expiresAt->getTimestamp() - $now->getTimestamp());
    }

    public function belongsTo(string $riderId): bool
    {
        return $this->riderId === $riderId;
    }

    private function assertAnswerable(DateTimeImmutable $now): void
    {
        if (! $this->state->isAnswerable()) {
            throw OfferNoLongerAnswerable::because(sprintf(
                'This offer was already %s.',
                $this->state->value,
            ));
        }

        if ($this->hasExpired($now)) {
            throw OfferNoLongerAnswerable::because('This offer expired before it was answered.');
        }
    }

    private function touch(): void
    {
        // The version is advanced by the repository on a successful write; the
        // aggregate carries the value it was read at so the optimistic check
        // has something to compare.
    }

    public function id(): string
    {
        return $this->id;
    }

    public function requestId(): string
    {
        return $this->requestId;
    }

    public function riderId(): string
    {
        return $this->riderId;
    }

    public function deliveryId(): string
    {
        return $this->deliveryId;
    }

    public function vehicleId(): ?string
    {
        return $this->vehicleId;
    }

    public function score(): float
    {
        return $this->score;
    }

    public function breakdown(): ?ScoreBreakdown
    {
        return $this->breakdown;
    }

    public function etaSeconds(): ?int
    {
        return $this->etaSeconds;
    }

    public function distanceMetres(): ?int
    {
        return $this->distanceMetres;
    }

    public function state(): OfferState
    {
        return $this->state;
    }

    public function respondedAt(): ?DateTimeImmutable
    {
        return $this->respondedAt;
    }

    public function declineReason(): ?string
    {
        return $this->declineReason;
    }

    public function offeredAt(): DateTimeImmutable
    {
        return $this->offeredAt;
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function version(): int
    {
        return $this->version;
    }
}
