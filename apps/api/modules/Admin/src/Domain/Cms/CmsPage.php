<?php

declare(strict_types=1);

namespace EruoFood\Admin\Domain\Cms;

use DateTimeImmutable;
use EruoFood\Admin\Domain\Exception\AdminInvalidState;
use EruoFood\Shared\Domain\ValueObject\Slug;

/**
 * A single piece of managed content — a page, blog post, news item, legal
 * document or help article. The aggregate root of the CMS: it owns its slug,
 * body, SEO metadata and publication lifecycle (draft → published → archived).
 */
final class CmsPage
{
    private function __construct(
        private readonly string $id,
        private readonly ContentType $type,
        private Slug $slug,
        private string $title,
        private string $body,
        private ?string $excerpt,
        private SeoMetadata $seo,
        private PublishStatus $status,
        private readonly string $authorId,
        private ?DateTimeImmutable $publishedAt,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
    }

    public static function draft(
        string $id,
        ContentType $type,
        Slug $slug,
        string $title,
        string $body,
        ?string $excerpt,
        SeoMetadata $seo,
        string $authorId,
        DateTimeImmutable $now,
    ): self {
        return new self($id, $type, $slug, $title, $body, $excerpt, $seo, PublishStatus::Draft, $authorId, null, $now, $now);
    }

    public static function reconstitute(
        string $id,
        ContentType $type,
        Slug $slug,
        string $title,
        string $body,
        ?string $excerpt,
        SeoMetadata $seo,
        PublishStatus $status,
        string $authorId,
        ?DateTimeImmutable $publishedAt,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ): self {
        return new self($id, $type, $slug, $title, $body, $excerpt, $seo, $status, $authorId, $publishedAt, $createdAt, $updatedAt);
    }

    public function edit(string $title, string $body, ?string $excerpt, SeoMetadata $seo, DateTimeImmutable $now): void
    {
        $this->title = $title;
        $this->body = $body;
        $this->excerpt = $excerpt;
        $this->seo = $seo;
        $this->updatedAt = $now;
    }

    public function changeSlug(Slug $slug, DateTimeImmutable $now): void
    {
        $this->slug = $slug;
        $this->updatedAt = $now;
    }

    public function publish(DateTimeImmutable $now): void
    {
        if ($this->status === PublishStatus::Archived) {
            throw new AdminInvalidState('An archived page must be restored to draft before publishing.');
        }
        $this->status = PublishStatus::Published;
        $this->publishedAt ??= $now;
        $this->updatedAt = $now;
    }

    public function unpublish(DateTimeImmutable $now): void
    {
        if ($this->status !== PublishStatus::Published) {
            throw new AdminInvalidState('Only a published page can be unpublished.');
        }
        $this->status = PublishStatus::Draft;
        $this->updatedAt = $now;
    }

    public function archive(DateTimeImmutable $now): void
    {
        $this->status = PublishStatus::Archived;
        $this->updatedAt = $now;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function type(): ContentType
    {
        return $this->type;
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

    public function seo(): SeoMetadata
    {
        return $this->seo;
    }

    public function status(): PublishStatus
    {
        return $this->status;
    }

    public function isPublished(): bool
    {
        return $this->status === PublishStatus::Published;
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
