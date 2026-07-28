<?php

declare(strict_types=1);

namespace EruoFood\Reviews\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/**
 * Raised when a submitted review is held for moderation (content-filter hit or
 * pre-moderation posture) — cues the moderation queue / a moderator alert.
 */
final readonly class ReviewFlagged implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(
        public string $reviewId,
        public string $subjectType,
        public string $subjectId,
        public string $reason,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'reviews.review_flagged';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
