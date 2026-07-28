<?php

declare(strict_types=1);

namespace EruoFood\Reviews\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/**
 * Raised whenever a subject's rating summary is recomputed. This is how other
 * contexts (Catalog, Commerce, Marketplace, Search) learn a subject's rating —
 * they consume this event and update their own denormalised rating field,
 * instead of computing ratings themselves.
 */
final readonly class RatingSummaryUpdated implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(
        public string $subjectType,
        public string $subjectId,
        public int $count,
        public float $average,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'reviews.rating_summary_updated';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
