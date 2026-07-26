<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Domain\Vendor;

use DateTimeImmutable;
use EruoFood\Shared\Domain\Exception\InvalidArgumentException;

/** A customer's rating (1-5) and optional review of a vendor. */
final class VendorReview
{
    private function __construct(
        private readonly string $id,
        private readonly string $vendorId,
        private readonly string $userId,
        private int $rating,
        private ?string $comment,
        private readonly DateTimeImmutable $createdAt,
    ) {
    }

    public static function create(
        string $id,
        string $vendorId,
        string $userId,
        int $rating,
        ?string $comment,
        DateTimeImmutable $createdAt,
    ): self {
        self::guardRating($rating);

        return new self($id, $vendorId, $userId, $rating, $comment, $createdAt);
    }

    public function update(int $rating, ?string $comment): void
    {
        self::guardRating($rating);
        $this->rating = $rating;
        $this->comment = $comment;
    }

    private static function guardRating(int $rating): void
    {
        if ($rating < 1 || $rating > 5) {
            throw new InvalidArgumentException('Rating must be between 1 and 5.');
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function vendorId(): string
    {
        return $this->vendorId;
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
