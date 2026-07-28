<?php

declare(strict_types=1);

namespace EruoFood\Support\Domain\Knowledge;

use DateTimeImmutable;
use EruoFood\Shared\Domain\ValueObject\Slug;

/**
 * A knowledge-base article — a help article, FAQ or guide. The aggregate root of
 * the knowledge base. Each content edit bumps a monotonic `version` (the basis
 * for version history) and helpfulness votes accumulate to rank articles.
 */
final class Article
{
    /**
     * @param list<string> $tags
     */
    private function __construct(
        private readonly string $id,
        private Slug $slug,
        private string $title,
        private string $body,
        private ?string $excerpt,
        private string $category,
        private ArticleStatus $status,
        private int $version,
        private array $tags,
        private int $helpfulYes,
        private int $helpfulNo,
        private readonly string $authorId,
        private ?DateTimeImmutable $publishedAt,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * @param list<string> $tags
     */
    public static function draft(
        string $id,
        Slug $slug,
        string $title,
        string $body,
        ?string $excerpt,
        string $category,
        array $tags,
        string $authorId,
        DateTimeImmutable $now,
    ): self {
        return new self($id, $slug, $title, $body, $excerpt, $category, ArticleStatus::Draft, 1, $tags, 0, 0, $authorId, null, $now, $now);
    }

    /**
     * @param list<string> $tags
     */
    public static function reconstitute(
        string $id,
        Slug $slug,
        string $title,
        string $body,
        ?string $excerpt,
        string $category,
        ArticleStatus $status,
        int $version,
        array $tags,
        int $helpfulYes,
        int $helpfulNo,
        string $authorId,
        ?DateTimeImmutable $publishedAt,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ): self {
        return new self($id, $slug, $title, $body, $excerpt, $category, $status, $version, $tags, $helpfulYes, $helpfulNo, $authorId, $publishedAt, $createdAt, $updatedAt);
    }

    /**
     * @param list<string> $tags
     */
    public function edit(string $title, string $body, ?string $excerpt, string $category, array $tags, DateTimeImmutable $now): void
    {
        $this->title = $title;
        $this->body = $body;
        $this->excerpt = $excerpt;
        $this->category = $category;
        $this->tags = array_values($tags);
        $this->version++;
        $this->updatedAt = $now;
    }

    public function publish(DateTimeImmutable $now): void
    {
        $this->status = ArticleStatus::Published;
        $this->publishedAt ??= $now;
        $this->updatedAt = $now;
    }

    public function archive(DateTimeImmutable $now): void
    {
        $this->status = ArticleStatus::Archived;
        $this->updatedAt = $now;
    }

    public function vote(bool $helpful): void
    {
        $helpful ? $this->helpfulYes++ : $this->helpfulNo++;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function slug(): Slug
    {
        return $this->slug;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function excerpt(): ?string
    {
        return $this->excerpt;
    }

    public function category(): string
    {
        return $this->category;
    }

    public function status(): ArticleStatus
    {
        return $this->status;
    }

    public function isPublished(): bool
    {
        return $this->status === ArticleStatus::Published;
    }

    public function version(): int
    {
        return $this->version;
    }

    /** @return list<string> */
    public function tags(): array
    {
        return $this->tags;
    }

    public function helpfulYes(): int
    {
        return $this->helpfulYes;
    }

    public function helpfulNo(): int
    {
        return $this->helpfulNo;
    }

    public function authorId(): string
    {
        return $this->authorId;
    }

    public function publishedAt(): ?DateTimeImmutable
    {
        return $this->publishedAt;
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
