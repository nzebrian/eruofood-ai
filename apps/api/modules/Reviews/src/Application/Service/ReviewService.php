<?php

declare(strict_types=1);

namespace EruoFood\Reviews\Application\Service;

use DateTimeImmutable;
use EruoFood\Reviews\Application\Port\ContentModerator;
use EruoFood\Reviews\Domain\Enum\ReviewStatus;
use EruoFood\Reviews\Domain\Eligibility\PurchaseEligibilityRepository;
use EruoFood\Reviews\Domain\Event\ReviewFlagged;
use EruoFood\Reviews\Domain\Event\ReviewPublished;
use EruoFood\Reviews\Domain\Exception\ReviewsConflict;
use EruoFood\Reviews\Domain\Exception\ReviewsInvalidState;
use EruoFood\Reviews\Domain\Exception\ReviewsNotAuthorized;
use EruoFood\Reviews\Domain\Exception\ReviewsNotFound;
use EruoFood\Reviews\Domain\Rating\RatingSummary;
use EruoFood\Reviews\Domain\Rating\RatingSummaryRepository;
use EruoFood\Reviews\Domain\Review\Review;
use EruoFood\Reviews\Domain\Review\ReviewQuery;
use EruoFood\Reviews\Domain\Review\ReviewRepository;
use EruoFood\Reviews\Domain\ValueObject\Rating;
use EruoFood\Reviews\Domain\ValueObject\Subject;
use EruoFood\Shared\Domain\EventBus;
use EruoFood\Shared\Domain\Paginated;

/**
 * The one entry point for every review interaction — no business module stores
 * reviews. On submission it enforces one-review-per-subject, stamps
 * verified-purchase from the eligibility ledger, screens content, and decides
 * (per the moderation posture) whether to auto-publish or hold for a moderator.
 * Publishing recomputes the subject's rating summary via the projector and
 * publishes domain events; the summary is the only rating other contexts read.
 */
final readonly class ReviewService
{
    public function __construct(
        private ReviewRepository $reviews,
        private RatingSummaryRepository $summaries,
        private PurchaseEligibilityRepository $eligibility,
        private ContentModerator $moderator,
        private RatingProjector $projector,
        private EventBus $events,
        private string $moderationPosture,
        private bool $contentFilter,
        private int $maxPhotos,
    ) {
    }

    /**
     * @param list<string> $photos
     */
    public function submit(
        string $authorId,
        Subject $subject,
        int $rating,
        ?string $title,
        ?string $body,
        array $photos,
    ): Review {
        if ($this->reviews->findBySubjectAndAuthor($subject, $authorId) !== null) {
            throw new ReviewsConflict('You have already reviewed this item.');
        }
        if (count($photos) > $this->maxPhotos) {
            throw new ReviewsInvalidState(sprintf('At most %d photos are allowed.', $this->maxPhotos));
        }

        $verified = $this->eligibility->isEligible($authorId, $subject);

        $flagReason = null;
        if ($this->contentFilter) {
            $screen = $this->moderator->screen(trim(($title ?? '').' '.($body ?? '')));
            if (! $screen['ok']) {
                $flagReason = $screen['reason'] ?? 'flagged by content filter';
            }
        }

        $holdForModeration = $this->moderationPosture === 'pre' || $flagReason !== null;
        $status = $holdForModeration ? ReviewStatus::Pending : ReviewStatus::Published;

        $review = Review::create(
            $this->reviews->nextIdentity(),
            $subject,
            $authorId,
            new Rating($rating),
            $title,
            $body,
            array_values($photos),
            $verified,
            $status,
            new DateTimeImmutable(),
        );
        $this->reviews->save($review);

        if ($status === ReviewStatus::Published) {
            $this->projector->project($subject);
            $this->events->publish(new ReviewPublished($review->id(), $subject->type->value, $subject->id, $authorId, $rating));
        } else {
            $this->events->publish(new ReviewFlagged($review->id(), $subject->type->value, $subject->id, $flagReason ?? 'pre-moderation'));
        }

        return $review;
    }

    public function vote(string $reviewId, bool $helpful): Review
    {
        $review = $this->require($reviewId);
        if (! $review->status()->isVisible()) {
            throw new ReviewsInvalidState('Only a published review can be voted on.');
        }
        $review->voteHelpful($helpful);
        $this->reviews->save($review);

        return $review;
    }

    public function respond(string $reviewId, string $responderId, string $body): Review
    {
        $review = $this->require($reviewId);
        if (! $review->status()->isVisible()) {
            throw new ReviewsInvalidState('Only a published review can be responded to.');
        }
        $review->respond($responderId, $body, new DateTimeImmutable());
        $this->reviews->save($review);

        return $review;
    }

    /**
     * @param list<string> $photos
     */
    public function edit(string $reviewId, string $authorId, int $rating, ?string $title, ?string $body, array $photos): Review
    {
        $review = $this->require($reviewId);
        if ($review->authorId() !== $authorId) {
            throw new ReviewsNotAuthorized('You may only edit your own review.');
        }
        if (count($photos) > $this->maxPhotos) {
            throw new ReviewsInvalidState(sprintf('At most %d photos are allowed.', $this->maxPhotos));
        }
        $review->edit(new Rating($rating), $title, $body, array_values($photos), new DateTimeImmutable());
        $this->reviews->save($review);
        if ($review->status()->countsToRating()) {
            $this->projector->project($review->subject());
        }

        return $review;
    }

    /**
     * @return Paginated<Review>
     */
    public function listForSubject(Subject $subject, string $sort, bool $verifiedOnly, int $page, int $perPage): Paginated
    {
        return $this->reviews->search(new ReviewQuery(
            subject: $subject,
            status: ReviewStatus::Published,
            verifiedOnly: $verifiedOnly,
            sort: $sort,
            page: $page,
            perPage: $perPage,
        ));
    }

    /**
     * @return Paginated<Review>
     */
    public function myReviews(string $authorId, int $page, int $perPage): Paginated
    {
        return $this->reviews->search(new ReviewQuery(authorId: $authorId, sort: 'newest', page: $page, perPage: $perPage));
    }

    public function summary(Subject $subject): RatingSummary
    {
        return $this->summaries->findBySubject($subject) ?? RatingSummary::empty($subject, new DateTimeImmutable());
    }

    public function get(string $reviewId): Review
    {
        return $this->require($reviewId);
    }

    private function require(string $reviewId): Review
    {
        return $this->reviews->findById($reviewId) ?? throw ReviewsNotFound::of('review', $reviewId);
    }
}
