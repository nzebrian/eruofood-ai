<?php

declare(strict_types=1);

namespace EruoFood\Support\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Shared\Domain\Paginated;
use EruoFood\Shared\Domain\ValueObject\Slug;
use EruoFood\Support\Domain\Knowledge\Article;
use EruoFood\Support\Domain\Knowledge\ArticleRepository;
use EruoFood\Support\Domain\Knowledge\ArticleStatus;
use EruoFood\Support\Infrastructure\Persistence\Eloquent\Model\ArticleModel;
use Illuminate\Support\Str;

final class EloquentArticleRepository implements ArticleRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?Article
    {
        $m = ArticleModel::query()->find($id);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function findBySlug(Slug $slug): ?Article
    {
        $m = ArticleModel::query()->where('slug', $slug->value)->first();

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function search(?string $term, ?string $category, ?ArticleStatus $status, int $page, int $perPage): Paginated
    {
        $builder = ArticleModel::query();
        if ($term !== null && $term !== '') {
            $like = '%'.mb_strtolower($term).'%';
            $builder->where(function ($q) use ($like): void {
                $q->whereRaw('LOWER(title) LIKE ?', [$like])->orWhereRaw('LOWER(body) LIKE ?', [$like]);
            });
        }
        if ($category !== null) {
            $builder->where('category', $category);
        }
        if ($status !== null) {
            $builder->where('status', $status->value);
        }

        $paginator = $builder->orderByDesc('updated_at')->paginate(perPage: $perPage, page: $page);

        return new Paginated(
            array_map(fn (ArticleModel $m): Article => $this->toDomain($m), $paginator->items()),
            $paginator->total(),
            $page,
            $perPage,
        );
    }

    public function categories(): array
    {
        /** @var list<string> $cats */
        $cats = ArticleModel::query()->select('category')->distinct()->orderBy('category')->pluck('category')->all();

        return array_values(array_map('strval', $cats));
    }

    public function save(Article $article): void
    {
        $model = ArticleModel::query()->find($article->id()) ?? new ArticleModel();
        $model->id = $article->id();
        $model->slug = $article->slug()->value;
        $model->title = $article->title();
        $model->body = $article->body();
        $model->excerpt = $article->excerpt();
        $model->category = $article->category();
        $model->status = $article->status()->value;
        $model->version = $article->version();
        $model->tags = $article->tags();
        $model->helpful_yes = $article->helpfulYes();
        $model->helpful_no = $article->helpfulNo();
        $model->author_id = $article->authorId();
        $model->published_at = $article->publishedAt();
        $model->created_at = $article->createdAt();
        $model->updated_at = $article->updatedAt();
        $model->save();
    }

    public function delete(string $id): void
    {
        ArticleModel::query()->whereKey($id)->delete();
    }

    private function toDomain(ArticleModel $m): Article
    {
        return Article::reconstitute(
            id: $m->id,
            slug: new Slug($m->slug),
            title: $m->title,
            body: (string) $m->body,
            excerpt: $m->excerpt,
            category: $m->category,
            status: ArticleStatus::from($m->status),
            version: (int) $m->version,
            tags: array_values(array_map('strval', $m->tags ?? [])),
            helpfulYes: (int) $m->helpful_yes,
            helpfulNo: (int) $m->helpful_no,
            authorId: $m->author_id,
            publishedAt: $m->published_at !== null ? DateTimeImmutable::createFromInterface($m->published_at) : null,
            createdAt: DateTimeImmutable::createFromInterface($m->created_at),
            updatedAt: DateTimeImmutable::createFromInterface($m->updated_at),
        );
    }
}
