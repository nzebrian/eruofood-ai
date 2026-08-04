<?php

declare(strict_types=1);

namespace EruoFood\Support\Application\Service;

use DateTimeImmutable;
use EruoFood\Shared\Domain\Paginated;
use EruoFood\Shared\Domain\ValueObject\Slug;
use EruoFood\Support\Domain\Exception\SupportConflict;
use EruoFood\Support\Domain\Exception\SupportNotFound;
use EruoFood\Support\Domain\Knowledge\Article;
use EruoFood\Support\Domain\Knowledge\ArticleRepository;
use EruoFood\Support\Domain\Knowledge\ArticleStatus;

/**
 * The knowledge base: help articles, FAQs and guides — authored and versioned by
 * content managers, browsed and searched (and voted on) by customers. Only
 * published articles are visible publicly.
 */
final readonly class KnowledgeBaseService
{
    public function __construct(
        private ArticleRepository $articles,
    ) {
    }

    /**
     * @param list<string> $tags
     */
    public function create(string $authorId, string $title, string $body, ?string $excerpt, string $category, array $tags, ?string $slug = null): Article
    {
        $slugVo = $slug !== null ? new Slug($slug) : Slug::fromTitle($title);
        if ($this->articles->findBySlug($slugVo) !== null) {
            throw new SupportConflict(sprintf('An article with slug "%s" already exists.', $slugVo->value));
        }
        $article = Article::draft($this->articles->nextIdentity(), $slugVo, $title, $body, $excerpt, $category, $tags, $authorId, new DateTimeImmutable());
        $this->articles->save($article);

        return $article;
    }

    /**
     * @param list<string> $tags
     */
    public function update(string $id, string $title, string $body, ?string $excerpt, string $category, array $tags): Article
    {
        $article = $this->require($id);
        $article->edit($title, $body, $excerpt, $category, $tags, new DateTimeImmutable());
        $this->articles->save($article);

        return $article;
    }

    public function publish(string $id): Article
    {
        $article = $this->require($id);
        $article->publish(new DateTimeImmutable());
        $this->articles->save($article);

        return $article;
    }

    public function archive(string $id): Article
    {
        $article = $this->require($id);
        $article->archive(new DateTimeImmutable());
        $this->articles->save($article);

        return $article;
    }

    public function delete(string $id): void
    {
        $this->require($id);
        $this->articles->delete($id);
    }

    public function vote(string $slug, bool $helpful): Article
    {
        $article = $this->articles->findBySlug(new Slug($slug)) ?? throw SupportNotFound::of('article', $slug);
        $article->vote($helpful);
        $this->articles->save($article);

        return $article;
    }

    /**
     * @return Paginated<Article>
     */
    public function search(?string $term, ?string $category, ?ArticleStatus $status, int $page, int $perPage): Paginated
    {
        return $this->articles->search($term, $category, $status, $page, $perPage);
    }

    public function get(string $id): Article
    {
        return $this->require($id);
    }

    public function getPublishedBySlug(string $slug): Article
    {
        $article = $this->articles->findBySlug(new Slug($slug));
        if ($article === null || ! $article->isPublished()) {
            throw SupportNotFound::of('article', $slug);
        }

        return $article;
    }

    /** @return list<string> */
    public function categories(): array
    {
        return array_values($this->articles->categories());
    }

    private function require(string $id): Article
    {
        return $this->articles->findById($id) ?? throw SupportNotFound::of('article', $id);
    }
}
