<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Infrastructure\Persistence\Eloquent;

use EruoFood\Catalog\Domain\Enum\ContentStatus;
use EruoFood\Catalog\Domain\Enum\Difficulty;
use EruoFood\Catalog\Domain\Recipe\Recipe;
use EruoFood\Catalog\Domain\Recipe\RecipeRepository;
use EruoFood\Catalog\Domain\Recipe\RecipeSearchCriteria;
use EruoFood\Catalog\Domain\ValueObject\CookingStep;
use EruoFood\Catalog\Domain\ValueObject\RecipeIngredient;
use EruoFood\Catalog\Infrastructure\Persistence\Eloquent\Model\RecipeModel;
use EruoFood\Shared\Domain\EventBus;
use EruoFood\Shared\Domain\Paginated;
use EruoFood\Shared\Domain\ValueObject\Slug;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Str;

final readonly class EloquentRecipeRepository implements RecipeRepository
{
    public function __construct(private EventBus $events)
    {
    }

    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?Recipe
    {
        $model = RecipeModel::query()->find($id);

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function findBySlug(string $slug): ?Recipe
    {
        $model = RecipeModel::query()->where('slug', $slug)->first();

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function existsBySlug(string $slug): bool
    {
        return RecipeModel::query()->where('slug', $slug)->exists();
    }

    public function search(RecipeSearchCriteria $criteria, int $page, int $perPage): Paginated
    {
        $query = RecipeModel::query()
            ->when($criteria->status, fn (Builder $q) => $q->where('status', $criteria->status->value))
            ->when($criteria->foodId, fn (Builder $q) => $q->where('food_id', $criteria->foodId))
            ->when($criteria->authorId, fn (Builder $q) => $q->where('author_id', $criteria->authorId))
            ->when($criteria->difficulty, fn (Builder $q) => $q->where('difficulty', $criteria->difficulty->value))
            ->when($criteria->tag, fn (Builder $q) => $q->whereJsonContains('tags', $criteria->tag))
            ->when($criteria->term, fn (Builder $q) => $q->whereRaw(
                'LOWER(title) LIKE ?',
                ['%'.strtolower((string) $criteria->term).'%'],
            ))
            ->when($criteria->maxTotalMinutes, fn (Builder $q) => $q->whereRaw(
                '(prep_time_minutes + cook_time_minutes) <= ?',
                [$criteria->maxTotalMinutes],
            ));

        $query = match ($criteria->sort) {
            'rating' => $query->orderByDesc('rating_average')->orderByDesc('rating_count'),
            'quick' => $query->orderByRaw('(prep_time_minutes + cook_time_minutes) asc'),
            default => $query->orderByDesc('created_at'),
        };

        $paginator = $query->paginate(perPage: $perPage, page: $page);

        return new Paginated(
            array_map(fn (RecipeModel $m): Recipe => $this->toDomain($m), $paginator->items()),
            $paginator->total(),
            $page,
            $perPage,
        );
    }

    public function findManyByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return RecipeModel::query()
            ->whereIn('id', $ids)
            ->get()
            ->map(fn (RecipeModel $m): Recipe => $this->toDomain($m))
            ->all();
    }

    public function save(Recipe $recipe): void
    {
        $model = RecipeModel::query()->find($recipe->id()) ?? new RecipeModel();
        $model->id = $recipe->id();
        $model->food_id = $recipe->foodId();
        $model->author_id = $recipe->authorId();
        $model->title = $recipe->title();
        $model->slug = $recipe->slug()->value;
        $model->summary = $recipe->summary();
        $model->prep_time_minutes = $recipe->prepTimeMinutes();
        $model->cook_time_minutes = $recipe->cookTimeMinutes();
        $model->difficulty = $recipe->difficulty()->value;
        $model->serving_size = $recipe->servingSize();
        $model->ingredients = array_map(static fn (RecipeIngredient $i): array => $i->toArray(), $recipe->ingredients());
        $model->steps = array_map(static fn (CookingStep $s): array => $s->toArray(), $recipe->steps());
        $model->tips = $recipe->tips();
        $model->tags = $recipe->tags();
        $model->related_recipe_ids = $recipe->relatedRecipeIds();
        $model->status = $recipe->status()->value;
        $model->version = $recipe->version();
        $model->rating_average = $recipe->ratingAverage();
        $model->rating_count = $recipe->ratingCount();
        $model->save();

        $this->events->publish(...$recipe->releaseEvents());
    }

    public function delete(string $id): void
    {
        RecipeModel::query()->whereKey($id)->delete();
    }

    private function toDomain(RecipeModel $m): Recipe
    {
        return Recipe::reconstitute(
            id: $m->id,
            foodId: $m->food_id,
            authorId: $m->author_id,
            title: $m->title,
            slug: new Slug($m->slug),
            summary: $m->summary,
            prepTimeMinutes: $m->prep_time_minutes,
            cookTimeMinutes: $m->cook_time_minutes,
            difficulty: Difficulty::from($m->difficulty),
            servingSize: $m->serving_size,
            ingredients: array_map(
                static fn (array $i): RecipeIngredient => RecipeIngredient::fromArray($i),
                $m->ingredients ?? [],
            ),
            steps: array_map(static fn (array $s): CookingStep => CookingStep::fromArray($s), $m->steps ?? []),
            tips: $m->tips ?? [],
            tags: $m->tags ?? [],
            relatedRecipeIds: $m->related_recipe_ids ?? [],
            status: ContentStatus::from($m->status),
            version: $m->version,
            ratingAverage: (float) $m->rating_average,
            ratingCount: $m->rating_count,
        );
    }
}
