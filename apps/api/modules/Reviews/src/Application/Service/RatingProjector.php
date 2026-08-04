<?php

declare(strict_types=1);

namespace EruoFood\Reviews\Application\Service;

use DateTimeImmutable;
use EruoFood\Reviews\Domain\Event\RatingSummaryUpdated;
use EruoFood\Reviews\Domain\Rating\RatingSummary;
use EruoFood\Reviews\Domain\Rating\RatingSummaryRepository;
use EruoFood\Reviews\Domain\Review\ReviewRepository;
use EruoFood\Reviews\Domain\ValueObject\Subject;
use EruoFood\Shared\Domain\EventBus;

/**
 * Recomputes a subject's authoritative rating summary from its published reviews
 * and publishes {@see RatingSummaryUpdated}. This is the single writer of the
 * summary and the single place ratings are calculated — other contexts consume
 * the event, they never compute ratings themselves.
 */
final readonly class RatingProjector
{
    public function __construct(
        private ReviewRepository $reviews,
        private RatingSummaryRepository $summaries,
        private EventBus $events,
    ) {
    }

    public function project(Subject $subject): RatingSummary
    {
        $published = $this->reviews->publishedForSubject($subject);
        $summary = RatingSummary::fromReviews($subject, $published, new DateTimeImmutable());
        $this->summaries->save($summary);

        $this->events->publish(new RatingSummaryUpdated(
            $subject->type->value,
            $subject->id,
            $summary->count,
            $summary->average,
        ));

        return $summary;
    }
}
