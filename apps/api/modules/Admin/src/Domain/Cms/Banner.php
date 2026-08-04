<?php

declare(strict_types=1);

namespace EruoFood\Admin\Domain\Cms;

use DateTimeImmutable;

/**
 * A dynamic promotional banner shown on the storefront (homepage hero, category
 * strip, etc.). Placement + sort order decide where and in what order it renders;
 * an optional active window bounds its visibility in time.
 */
final class Banner
{
    private function __construct(
        private readonly string $id,
        private string $title,
        private string $imageUrl,
        private ?string $linkUrl,
        private string $placement,
        private int $sortOrder,
        private bool $active,
        private ?DateTimeImmutable $startsAt,
        private ?DateTimeImmutable $endsAt,
        private readonly DateTimeImmutable $createdAt,
    ) {
    }

    public static function create(
        string $id,
        string $title,
        string $imageUrl,
        ?string $linkUrl,
        string $placement,
        int $sortOrder,
        ?DateTimeImmutable $startsAt,
        ?DateTimeImmutable $endsAt,
        DateTimeImmutable $now,
    ): self {
        return new self($id, $title, $imageUrl, $linkUrl, $placement, $sortOrder, true, $startsAt, $endsAt, $now);
    }

    public static function reconstitute(
        string $id,
        string $title,
        string $imageUrl,
        ?string $linkUrl,
        string $placement,
        int $sortOrder,
        bool $active,
        ?DateTimeImmutable $startsAt,
        ?DateTimeImmutable $endsAt,
        DateTimeImmutable $createdAt,
    ): self {
        return new self($id, $title, $imageUrl, $linkUrl, $placement, $sortOrder, $active, $startsAt, $endsAt, $createdAt);
    }

    public function update(
        string $title,
        string $imageUrl,
        ?string $linkUrl,
        string $placement,
        int $sortOrder,
        ?DateTimeImmutable $startsAt,
        ?DateTimeImmutable $endsAt,
    ): void {
        $this->title = $title;
        $this->imageUrl = $imageUrl;
        $this->linkUrl = $linkUrl;
        $this->placement = $placement;
        $this->sortOrder = $sortOrder;
        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;
    }

    public function activate(): void
    {
        $this->active = true;
    }

    public function deactivate(): void
    {
        $this->active = false;
    }

    /** Whether the banner should render at the given moment. */
    public function isVisibleAt(DateTimeImmutable $at): bool
    {
        if (! $this->active) {
            return false;
        }
        if ($this->startsAt !== null && $at < $this->startsAt) {
            return false;
        }
        if ($this->endsAt !== null && $at > $this->endsAt) {
            return false;
        }

        return true;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function imageUrl(): string
    {
        return $this->imageUrl;
    }

    public function linkUrl(): ?string
    {
        return $this->linkUrl;
    }

    public function placement(): string
    {
        return $this->placement;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function startsAt(): ?DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function endsAt(): ?DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
