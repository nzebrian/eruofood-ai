<?php

declare(strict_types=1);

namespace EruoFood\Reviews\Application\Service;

use DateTimeImmutable;
use EruoFood\Reviews\Domain\Enum\ReviewStatus;
use EruoFood\Reviews\Domain\Event\ReviewPublished;
use EruoFood\Reviews\Domain\Exception\ReviewsNotFound;
use EruoFood\Reviews\Domain\Review\Review;
use EruoFood\Reviews\Domain\Review\ReviewQuery;
use EruoFood\Reviews\Domain\Review\ReviewRepository;
use EruoFood\Reviews\Domain\ValueObject\Subject;
use EruoFood\Shared\Domain\EventBus;
use EruoFood\Shared\Domain\Paginated;

/**
 * The moderator workspace over held and published reviews. Approving a pending
 * review publishes it and recomputes its subject's rating summary; rejecting or
 * removing keeps the summary correct by re-projecting when a counted review
 * leaves the published set. The summary is only ever written by the projector,
 * so ratings stay consistent no matter which moderation path runs.
 */
final readonly class ModerationService
{
    public function __construct(
        private ReviewRepository $reviews,
        private RatingProjector $projector,
        private EventBus $events,
    ) {
    }

    /**
     * The moderation queue — reviews awaiting a decision, oldest first.
     *
     * @return Paginated<Review>
     */
    public function queue(int $page, int $perPage): Paginated
    {
        return $this->reviews->search(new ReviewQuery(
            status: ReviewStatus::Pending,
            sort: 'oldest',
            page: $page,
            perPage: $perPage,
        ));
    }

    public function approve(string $reviewId, string $moderatorId): Review
    {
        $review = $this->require($reviewId);
        $review->publish(new DateTimeImmutable());
        $this->reviews->save($review);

        $this->projector->project($review->subject());
        $this->events->publish(new ReviewPublished(
            $review->id(),
            $review->subject()->type->value,
            $review->subject()->id,
            $review->authorId(),
            $review->rating()->value,
        ));

        return $review;
    }

    public function reject(string $reviewId, string $moderatorId, string $reason): Review
    {
        $review = $this->require($reviewId);
        $countedBefore = $review->status()->countsToRating();
        $review->reject($moderatorId, $reason, new DateTimeImmutable());
        $this->reviews->save($review);
        if ($countedBefore) {
            $this->projector->project($review->subject());
        }

        return $review;
    }

    public function remove(string $reviewId, string $moderatorId, string $reason): Review
    {
        $review = $this->require($reviewId);
        $review->remove($moderatorId, $reason, new DateTimeImmutable());
        $this->reviews->save($review);
        // A removed review was published and therefore counted — re-project.
        $this->projector->project($review->subject());

        return $review;
    }

    private function require(string $reviewId): Review
    {
        return $this->reviews->findById($reviewId)
            ?? throw ReviewsNotFound::of('review', $reviewId);
    }
}
