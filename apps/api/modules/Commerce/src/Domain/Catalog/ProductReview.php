<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Domain\Catalog;

use DateTimeImmutable;
use EruoFood\Shared\Domain\Exception\InvalidArgumentException;

/** A customer's rating (1–5) and optional comment for a product. */
final class ProductReview
{
    private function __construct(
        private readonly string $id,
        private readonly string $productId,
        private readonly string $userId,
        private readonly int $rating,
        private readonly ?string $comment,
        private readonly DateTimeImmutable $createdAt,
    ) {
    }

    public static function create(
        string $id,
        string $productId,
        string $userId,
        int $rating,
        ?string $comment,
        DateTimeImmutable $now,
    ): self {
        if ($rating < 1 || $rating > 5) {
            throw new InvalidArgumentException('Rating must be between 1 and 5.');
        }

        return new self($id, $productId, $userId, $rating, $comment, $now);
    }

    public static function reconstitute(
        string $id,
        string $productId,
        string $userId,
        int $rating,
        ?string $comment,
        DateTimeImmutable $createdAt,
    ): self {
        return new self($id, $productId, $userId, $rating, $comment, $createdAt);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function productId(): string
    {
        return $this->productId;
    }

    public function userId(): string
    {
        return $this->userId;
    }

    public function rating(): int
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
