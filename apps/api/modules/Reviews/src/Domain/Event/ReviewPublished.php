<?php

declare(strict_types=1);

namespace EruoFood\Reviews\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/** Raised when a review goes live — cues notifications to the subject owner. */
final readonly class ReviewPublished implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(
        public string $reviewId,
        public string $subjectType,
        public string $subjectId,
        public string $authorId,
        public int $rating,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'reviews.review_published';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
