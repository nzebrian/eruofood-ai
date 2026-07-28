<?php

declare(strict_types=1);

namespace EruoFood\Admin\Domain\Cms;

use DateTimeImmutable;

/**
 * A single FAQ / help-centre entry. Grouped by a free-form category and ordered
 * within it; can be published or hidden without deletion.
 */
final class FaqItem
{
    private function __construct(
        private readonly string $id,
        private string $question,
        private string $answer,
        private string $category,
        private int $sortOrder,
        private bool $published,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
    }

    public static function create(
        string $id,
        string $question,
        string $answer,
        string $category,
        int $sortOrder,
        DateTimeImmutable $now,
    ): self {
        return new self($id, $question, $answer, $category, $sortOrder, true, $now, $now);
    }

    public static function reconstitute(
        string $id,
        string $question,
        string $answer,
        string $category,
        int $sortOrder,
        bool $published,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ): self {
        return new self($id, $question, $answer, $category, $sortOrder, $published, $createdAt, $updatedAt);
    }

    public function update(string $question, string $answer, string $category, int $sortOrder, DateTimeImmutable $now): void
    {
        $this->question = $question;
        $this->answer = $answer;
        $this->category = $category;
        $this->sortOrder = $sortOrder;
        $this->updatedAt = $now;
    }

    public function publish(DateTimeImmutable $now): void
    {
        $this->published = true;
        $this->updatedAt = $now;
    }

    public function hide(DateTimeImmutable $now): void
    {
        $this->published = false;
        $this->updatedAt = $now;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function question(): string
    {
        return $this->question;
    }

    public function answer(): string
    {
        return $this->answer;
    }

    public function category(): string
    {
        return $this->category;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }

    public function isPublished(): bool
    {
        return $this->published;
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
