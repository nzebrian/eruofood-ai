<?php

declare(strict_types=1);

namespace EruoFood\Reviews\Application\Service;

use EruoFood\Reviews\Domain\Rating\RatingSummary;
use EruoFood\Reviews\Domain\Review\Review;
use EruoFood\Reviews\Domain\ValueObject\OwnerResponse;

/** Maps Reviews domain objects to API-shaped arrays. */
final readonly class ReviewPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function review(Review $r): array
    {
        return [
            'id' => $r->id(),
            'subject_type' => $r->subject()->type->value,
            'subject_id' => $r->subject()->id,
            'author_id' => $r->authorId(),
            'rating' => $r->rating()->value,
            'title' => $r->title(),
            'body' => $r->body(),
            'photos' => $r->photos(),
            'verified_purchase' => $r->isVerifiedPurchase(),
            'status' => $r->status()->value,
            'helpful_yes' => $r->helpfulYes(),
            'helpful_no' => $r->helpfulNo(),
            'owner_response' => $r->ownerResponse() !== null ? $this->ownerResponse($r->ownerResponse()) : null,
            'created_at' => $r->createdAt()->format(DATE_ATOM),
            'updated_at' => $r->updatedAt()->format(DATE_ATOM),
        ];
    }

    /**
     * The moderator-facing shape — adds who moderated it and why.
     *
     * @return array<string, mixed>
     */
    public function moderationView(Review $r): array
    {
        return $this->review($r) + [
            'moderated_by' => $r->moderatedBy(),
            'moderation_reason' => $r->moderationReason(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function ownerResponse(OwnerResponse $response): array
    {
        return $response->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(RatingSummary $summary): array
    {
        return $summary->toArray();
    }
}
