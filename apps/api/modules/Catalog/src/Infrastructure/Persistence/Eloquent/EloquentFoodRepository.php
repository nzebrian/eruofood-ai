<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Infrastructure\Persistence\Eloquent;

use EruoFood\Catalog\Domain\Enum\ContentStatus;
use EruoFood\Catalog\Domain\Enum\FoodRegion;
use EruoFood\Catalog\Domain\Food\Food;
use EruoFood\Catalog\Domain\Food\FoodRepository;
use EruoFood\Catalog\Domain\Food\FoodSearchCriteria;
use EruoFood\Catalog\Domain\ValueObject\LocalName;
use EruoFood\Catalog\Domain\ValueObject\NutritionalInfo;
use EruoFood\Catalog\Infrastructure\Persistence\Eloquent\Model\FoodModel;
use EruoFood\Shared\Domain\EventBus;
use EruoFood\Shared\Domain\Paginated;
use EruoFood\Shared\Domain\ValueObject\Slug;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Str;

final readonly class EloquentFoodRepository implements FoodRepository
{
    public function __construct(private EventBus $events)
    {
    }

    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?Food
    {
        $model = FoodModel::query()->find($id);

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function findBySlug(string $slug): ?Food
    {
        $model = FoodModel::query()->where('slug', $slug)->first();

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function existsBySlug(string $slug): bool
    {
        return FoodModel::query()->where('slug', $slug)->exists();
    }

    public function search(FoodSearchCriteria $criteria, int $page, int $perPage): Paginated
    {
        $query = FoodModel::query()
            ->when($criteria->status, fn (Builder $q) => $q->where('status', $criteria->status->value))
            ->when($criteria->categoryId, fn (Builder $q) => $q->where('category_id', $criteria->categoryId))
            ->when($criteria->region, fn (Builder $q) => $q->where('region', $criteria->region->value))
            ->when($criteria->term, fn (Builder $q) => $q->whereRaw(
                'LOWER(name) LIKE ?',
                ['%'.strtolower((string) $criteria->term).'%'],
            ))
            ->when($criteria->state, fn (Builder $q) => $q->whereJsonContains('states', $criteria->state))
            ->when($criteria->tag, fn (Builder $q) => $q->whereJsonContains('tags', $criteria->tag));

        $query = match ($criteria->sort) {
            'recent' => $query->orderByDesc('created_at'),
            default => $query->orderBy('name'),
        };

        $paginator = $query->paginate(perPage: $perPage, page: $page);

        return new Paginated(
            array_values(array_map(fn (FoodModel $m): Food => $this->toDomain($m), $paginator->items())),
            $paginator->total(),
            $page,
            $perPage,
        );
    }

    public function save(Food $food): void
    {
        $model = FoodModel::query()->find($food->id()) ?? new FoodModel();
        $model->id = $food->id();
        $model->name = $food->name();
        $model->slug = $food->slug()->value;
        $model->description = $food->description();
        $model->category_id = $food->categoryId();
        $model->region = $food->region()->value;
        $model->states = $food->states();
        $model->local_names = array_map(static fn (LocalName $l): array => $l->toArray(), $food->localNames());
        $model->nutrition = $food->nutrition()?->toArray();
        $model->images = $food->images();
        $model->video_url = $food->videoUrl();
        $model->tags = $food->tags();
        $model->status = $food->status()->value;
        $model->save();

        $this->events->publish(...$food->releaseEvents());
    }

    public function delete(string $id): void
    {
        FoodModel::query()->whereKey($id)->delete();
    }

    private function toDomain(FoodModel $m): Food
    {
        return Food::reconstitute(
            id: $m->id,
            name: $m->name,
            slug: new Slug($m->slug),
            description: $m->description,
            categoryId: $m->category_id,
            region: FoodRegion::from($m->region),
            states: $m->states ?? [],
            localNames: array_map(static fn (array $l): LocalName => LocalName::fromArray($l), $m->local_names ?? []),
            nutrition: $m->nutrition !== null ? NutritionalInfo::fromArray($m->nutrition) : null,
            images: $m->images ?? [],
            videoUrl: $m->video_url,
            tags: $m->tags ?? [],
            status: ContentStatus::from($m->status),
        );
    }
}
