<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Domain\Recipe;

use DateTimeImmutable;
use EruoFood\Catalog\Domain\ValueObject\Rating;

/** A user's rating + optional written review of a recipe. */
final class RecipeReview
{
    private function __construct(
        private readonly string $id,
        private readonly string $recipeId,
        private readonly string $userId,
        private Rating $rating,
        private ?string $comment,
        private readonly DateTimeImmutable $createdAt,
    ) {
    }

    public static function create(
        string $id,
        string $recipeId,
        string $userId,
        Rating $rating,
        ?string $comment,
        DateTimeImmutable $createdAt,
    ): self {
        return new self($id, $recipeId, $userId, $rating, $comment, $createdAt);
    }

    public function update(Rating $rating, ?string $comment): void
    {
        $this->rating = $rating;
        $this->comment = $comment;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function recipeId(): string
    {
        return $this->recipeId;
    }

    public function userId(): string
    {
        return $this->userId;
    }

    public function rating(): Rating
    {
        return $this->rating;
    }

    public function comment(): ?string
    {
        return $this->comment;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
