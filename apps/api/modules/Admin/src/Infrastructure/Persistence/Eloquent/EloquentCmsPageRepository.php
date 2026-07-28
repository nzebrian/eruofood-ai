<?php

declare(strict_types=1);

namespace EruoFood\Admin\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Admin\Domain\Cms\CmsPage;
use EruoFood\Admin\Domain\Cms\CmsPageRepository;
use EruoFood\Admin\Domain\Cms\ContentType;
use EruoFood\Admin\Domain\Cms\PublishStatus;
use EruoFood\Admin\Domain\Cms\SeoMetadata;
use EruoFood\Admin\Infrastructure\Persistence\Eloquent\Model\CmsPageModel;
use EruoFood\Shared\Domain\Paginated;
use EruoFood\Shared\Domain\ValueObject\Slug;
use Illuminate\Support\Str;

final class EloquentCmsPageRepository implements CmsPageRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?CmsPage
    {
        $m = CmsPageModel::query()->find($id);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function findByTypeAndSlug(ContentType $type, Slug $slug): ?CmsPage
    {
        $m = CmsPageModel::query()->where('type', $type->value)->where('slug', $slug->value)->first();

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function search(?ContentType $type, ?PublishStatus $status, int $page, int $perPage): Paginated
    {
        $builder = CmsPageModel::query();
        if ($type !== null) {
            $builder->where('type', $type->value);
        }
        if ($status !== null) {
            $builder->where('status', $status->value);
        }
        $paginator = $builder->orderByDesc('updated_at')->paginate(perPage: $perPage, page: $page);

        return new Paginated(
            array_map(fn (CmsPageModel $m): CmsPage => $this->toDomain($m), $paginator->items()),
            $paginator->total(),
            $page,
            $perPage,
        );
    }

    public function save(CmsPage $page): void
    {
        $model = CmsPageModel::query()->find($page->id()) ?? new CmsPageModel();
        $model->id = $page->id();
        $model->type = $page->type()->value;
        $model->slug = $page->slug()->value;
        $model->title = $page->title();
        $model->body = $page->body();
        $model->excerpt = $page->excerpt();
        $model->seo = [
            'meta_title' => $page->seo()->metaTitle,
            'meta_description' => $page->seo()->metaDescription,
            'keywords' => $page->seo()->keywords,
            'og_image' => $page->seo()->ogImage,
        ];
        $model->status = $page->status()->value;
        $model->author_id = $page->authorId();
        $model->published_at = $page->publishedAt();
        $model->created_at = $page->createdAt();
        $model->updated_at = $page->updatedAt();
        $model->save();
    }

    public function delete(string $id): void
    {
        CmsPageModel::query()->whereKey($id)->delete();
    }

    private function toDomain(CmsPageModel $m): CmsPage
    {
        /** @var array<string, mixed> $seo */
        $seo = $m->seo ?? [];
        /** @var list<string> $keywords */
        $keywords = is_array($seo['keywords'] ?? null) ? $seo['keywords'] : [];

        return CmsPage::reconstitute(
            id: $m->id,
            type: ContentType::from($m->type),
            slug: new Slug($m->slug),
            title: $m->title,
            body: $m->body,
            excerpt: $m->excerpt,
            seo: new SeoMetadata(
                $seo['meta_title'] ?? null,
                $seo['meta_description'] ?? null,
                array_values(array_map('strval', $keywords)),
                $seo['og_image'] ?? null,
            ),
            status: PublishStatus::from($m->status),
            authorId: $m->author_id,
            publishedAt: $m->published_at !== null ? DateTimeImmutable::createFromInterface($m->published_at) : null,
            createdAt: DateTimeImmutable::createFromInterface($m->created_at),
            updatedAt: DateTimeImmutable::createFromInterface($m->updated_at),
        );
    }
}
