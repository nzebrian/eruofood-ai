<?php

declare(strict_types=1);

namespace EruoFood\Geo\Domain\Rider;

use DateTimeImmutable;
use EruoFood\Geo\Domain\Enum\LocationSource;
use EruoFood\Geo\Domain\ValueObject\Coordinates;

/**
 * Where a rider currently is.
 *
 * The field that makes this useful is `recordedAt`. The pre-M25 columns on
 * `marketplace_riders` had coordinates and no timestamp, so there was no way to
 * distinguish a rider who moved five seconds ago from one who last reported
 * five days ago — and a dispatch system built on that would confidently send
 * work to somebody who went home on Friday.
 *
 * Current position only. A movement history is what live tracking will need in
 * a later milestone, and building one now would mean accumulating a detailed
 * record of where every rider goes with nothing in M25 that reads it.
 */
final class RiderLocation
{
    private function __construct(
        private readonly string $riderId,
        private readonly string $userId,
        private Coordinates $coordinates,
        private ?float $accuracyMetres,
        private ?float $headingDegrees,
        private ?float $speedMps,
        private LocationSource $source,
        private DateTimeImmutable $recordedAt,
    ) {
    }

    public static function report(
        string $riderId,
        string $userId,
        Coordinates $coordinates,
        DateTimeImmutable $recordedAt,
        ?float $accuracyMetres = null,
        ?float $headingDegrees = null,
        ?float $speedMps = null,
        LocationSource $source = LocationSource::Device,
    ): self {
        return new self(
            $riderId,
            $userId,
            $coordinates,
            $accuracyMetres === null ? null : max(0.0, $accuracyMetres),
            $headingDegrees,
            $speedMps,
            $source,
            $recordedAt,
        );
    }

    public static function reconstitute(
        string $riderId,
        string $userId,
        Coordinates $coordinates,
        ?float $accuracyMetres,
        ?float $headingDegrees,
        ?float $speedMps,
        LocationSource $source,
        DateTimeImmutable $recordedAt,
    ): self {
        return new self($riderId, $userId, $coordinates, $accuracyMetres, $headingDegrees, $speedMps, $source, $recordedAt);
    }

    /**
     * Whether this position is too old to act on.
     *
     * A domain question rather than a query convention, because every consumer
     * has to ask it and none of them should answer it differently.
     */
    public function isStale(DateTimeImmutable $now, int $ttlSeconds): bool
    {
        return ($now->getTimestamp() - $this->recordedAt->getTimestamp()) > $ttlSeconds;
    }

    public function ageSeconds(DateTimeImmutable $now): int
    {
        return max(0, $now->getTimestamp() - $this->recordedAt->getTimestamp());
    }

    /**
     * Whether the fix is precise enough to be worth acting on.
     *
     * A position with a two-kilometre accuracy radius is not a position; using
     * it for proximity would produce confident nonsense.
     */
    public function isPreciseEnough(float $maxAccuracyMetres = 250.0): bool
    {
        return $this->accuracyMetres === null || $this->accuracyMetres <= $maxAccuracyMetres;
    }

    /** Ownership check — a rider may only ever write their own position. */
    public function belongsTo(string $userId): bool
    {
        return $this->userId === $userId;
    }

    public function riderId(): string
    {
        return $this->riderId;
    }

    public function userId(): string
    {
        return $this->userId;
    }

    public function coordinates(): Coordinates
    {
        return $this->coordinates;
    }

    public function accuracyMetres(): ?float
    {
        return $this->accuracyMetres;
    }

    public function headingDegrees(): ?float
    {
        return $this->headingDegrees;
    }

    public function speedMps(): ?float
    {
        return $this->speedMps;
    }

    public function source(): LocationSource
    {
        return $this->source;
    }

    public function recordedAt(): DateTimeImmutable
    {
        return $this->recordedAt;
    }
}
