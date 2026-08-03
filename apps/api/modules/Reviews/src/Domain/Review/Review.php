<?php

declare(strict_types=1);

namespace EruoFood\Reviews\Domain\Review;

use DateTimeImmutable;
use EruoFood\Reviews\Domain\Enum\ReviewStatus;
use EruoFood\Reviews\Domain\Exception\ReviewsInvalidState;
use EruoFood\Reviews\Domain\ValueObject\OwnerResponse;
use EruoFood\Reviews\Domain\ValueObject\Rating;
use EruoFood\Reviews\Domain\ValueObject\Subject;

/**
 * A single review — the aggregate root. It carries a star rating, optional
 * title/body/photos, whether the author is a verified purchaser, its moderation
 * status, helpfulness votes and an optional owner response. Only a Published
 * review is visible and counts toward its subject's rating summary; the status
 * transitions are guarded here so the summary projection stays correct.
 *
 * The author and subject are soft references (ids) into other contexts.
 */
final class Review
{
    /**
     * @param list<string> $photos
     */
    private function __construct(
        private readonly string $id,
        private readonly Subject $subject,
        private readonly string $authorId,
        private Rating $rating,
        private ?string $title,
        private ?string $body,
        private array $photos,
        private bool $verifiedPurchase,
        private ReviewStatus $status,
        private int $helpfulYes,
        private int $helpfulNo,
        private ?OwnerResponse $ownerResponse,
        private ?string $moderatedBy,
        private ?string $moderationReason,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * @param list<string> $photos
     */
    public static function create(
        string $id,
        Subject $subject,
        string $authorId,
        Rating $rating,
        ?string $title,
        ?string $body,
        array $photos,
        bool $verifiedPurchase,
        ReviewStatus $initialStatus,
        DateTimeImmutable $now,
    ): self {
        return new self(
            $id,
            $subject,
            $authorId,
            $rating,
            $title,
            $body,
            $photos,
            $verifiedPurchase,
            $initialStatus,
            0,
            0,
            null,
            null,
            null,
            $now,
            $now,
        );
    }

    /**
     * @param list<string> $photos
     */
    public static function reconstitute(
        string $id,
        Subject $subject,
        string $authorId,
        Rating $rating,
        ?string $title,
        ?string $body,
        array $photos,
        bool $verifiedPurchase,
        ReviewStatus $status,
        int $helpfulYes,
        int $helpfulNo,
        ?OwnerResponse $ownerResponse,
        ?string $moderatedBy,
        ?string $moderationReason,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ): self {
        return new self(
            $id,
            $subject,
            $authorId,
            $rating,
            $title,
            $body,
            $photos,
            $verifiedPurchase,
            $status,
            $helpfulYes,
            $helpfulNo,
            $ownerResponse,
            $moderatedBy,
            $moderationReason,
            $createdAt,
            $updatedAt,
        );
    }

    public function publish(DateTimeImmutable $now): void
    {
        if ($this->status === ReviewStatus::Rejected) {
            throw new ReviewsInvalidState('A rejected review cannot be published; the author must resubmit.');
        }
        $this->status = ReviewStatus::Published;
        $this->moderationReason = null;
        $this->updatedAt = $now;
    }

    public function reject(string $moderatorId, string $reason, DateTimeImmutable $now): void
    {
        $this->status = ReviewStatus::Rejected;
        $this->moderatedBy = $moderatorId;
        $this->moderationReason = $reason;
        $this->updatedAt = $now;
    }

    public function remove(string $moderatorId, string $reason, DateTimeImmutable $now): void
    {
        if ($this->status !== ReviewStatus::Published) {
            throw new ReviewsInvalidState('Only a published review can be removed.');
        }
        $this->status = ReviewStatus::Removed;
        $this->moderatedBy = $moderatorId;
        $this->moderationReason = $reason;
        $this->updatedAt = $now;
    }

    /**
     * @param list<string> $photos
     */
    public function edit(Rating $rating, ?string $title, ?string $body, array $photos, DateTimeImmutable $now): void
    {
        if ($this->status === ReviewStatus::Removed) {
            throw new ReviewsInvalidState('A removed review cannot be edited.');
        }
        $this->rating = $rating;
        $this->title = $title;
        $this->body = $body;
        $this->photos = $photos;
        $this->updatedAt = $now;
    }

    public function respond(string $responderId, string $body, DateTimeImmutable $now): void
    {
        $this->ownerResponse = new OwnerResponse($responderId, $body, $now);
        $this->updatedAt = $now;
    }

    public function voteHelpful(bool $helpful): void
    {
        $helpful ? $this->helpfulYes++ : $this->helpfulNo++;
    }

    public function markVerified(): void
    {
        $this->verifiedPurchase = true;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function subject(): Subject
    {
        return $this->subject;
    }

    public function authorId(): string
    {
        return $this->authorId;
    }

    public function rating(): Rating
    {
        return $this->rating;
    }

    public function title(): ?string
    {
        return $this->title;
    }

    public function body(): ?string
    {
        return $this->body;
    }

    /** @return list<string> */
    public function photos(): array
    {
        return $this->photos;
    }

    public function isVerifiedPurchase(): bool
    {
        return $this->verifiedPurchase;
    }

    public function status(): ReviewStatus
    {
        return $this->status;
    }

    public function helpfulYes(): int
    {
        return $this->helpfulYes;
    }

    public function helpfulNo(): int
    {
        return $this->helpfulNo;
    }

    public function ownerResponse(): ?OwnerResponse
    {
        return $this->ownerResponse;
    }

    public function moderatedBy(): ?string
    {
        return $this->moderatedBy;
    }

    public function moderationReason(): ?string
    {
        return $this->moderationReason;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
