<?php

declare(strict_types=1);

namespace EruoFood\Support\Domain\Knowledge;

use EruoFood\Shared\Domain\Paginated;
use EruoFood\Shared\Domain\ValueObject\Slug;

/** Persistence port for the {@see Article} aggregate. */
interface ArticleRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?Article;

    public function findBySlug(Slug $slug): ?Article;

    /**
     * @return Paginated<Article>
     */
    public function search(?string $term, ?string $category, ?ArticleStatus $status, int $page, int $perPage): Paginated;

    /** Distinct category names in use. @return list<string> */
    public function categories(): array;

    public function save(Article $article): void;

    public function delete(string $id): void;
}
