<?php

declare(strict_types=1);

namespace EruoFood\Geo\Domain\Route;

use DateTimeImmutable;

/**
 * How long a journey is expected to take, and how much that estimate is worth.
 *
 * An ETA is a measurement with a shelf life, not a property of the delivery.
 * Traffic-aware estimates go off quickly — a 25-minute estimate from an hour
 * ago says nothing useful about now — so this carries its own staleness rather
 * than leaving each caller to guess.
 */
final readonly class Eta
{
    public function __construct(
        public int $durationSeconds,
        public bool $trafficAware,
        public DateTimeImmutable $calculatedAt,
        public string $provider,
        public string $source,
        public ?float $confidence = null,
    ) {
    }

    public static function fromRoute(Route $route): self
    {
        return new self(
            $route->effectiveDurationSeconds(),
            $route->durationInTrafficSeconds !== null,
            $route->calculatedAt,
            $route->provider,
            $route->source->value,
        );
    }

    /**
     * Traffic-aware estimates expire far sooner than static ones, because the
     * traffic they accounted for has moved on.
     */
    public function isStale(DateTimeImmutable $now, int $staticTtl = 3600, int $trafficTtl = 300): bool
    {
        $age = $now->getTimestamp() - $this->calculatedAt->getTimestamp();

        return $age > ($this->trafficAware ? $trafficTtl : $staticTtl);
    }

    public function arrivalFrom(DateTimeImmutable $departingAt): DateTimeImmutable
    {
        return $departingAt->modify(sprintf('+%d seconds', $this->durationSeconds));
    }

    public function minutes(): int
    {
        return (int) ceil($this->durationSeconds / 60);
    }
}
