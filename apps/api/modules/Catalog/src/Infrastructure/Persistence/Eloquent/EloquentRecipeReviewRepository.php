<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Catalog\Domain\Recipe\RecipeReview;
use EruoFood\Catalog\Domain\Recipe\RecipeReviewRepository;
use EruoFood\Catalog\Domain\ValueObject\Rating;
use EruoFood\Catalog\Infrastructure\Persistence\Eloquent\Model\RecipeReviewModel;
use EruoFood\Shared\Domain\Paginated;

final class EloquentRecipeReviewRepository implements RecipeReviewRepository
{
    public function nextIdentity(): string
    {
        return (string) \Illuminate\Support\Str::orderedUuid();
    }

    public function findByRecipeAndUser(string $recipeId, string $userId): ?RecipeReview
    {
        $model = RecipeReviewModel::query()
            ->where('recipe_id', $recipeId)
            ->where('user_id', $userId)
            ->first();

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function forRecipe(string $recipeId, int $page, int $perPage): Paginated
    {
        $paginator = RecipeReviewModel::query()
            ->where('recipe_id', $recipeId)
            ->orderByDesc('created_at')
            ->paginate(perPage: $perPage, page: $page);

        return new Paginated(
            array_values(array_map(fn (RecipeReviewModel $m): RecipeReview => $this->toDomain($m), $paginator->items())),
            $paginator->total(),
            $page,
            $perPage,
        );
    }

    public function save(RecipeReview $review): void
    {
        $model = RecipeReviewModel::query()->find($review->id()) ?? new RecipeReviewModel();
        $model->id = $review->id();
        $model->recipe_id = $review->recipeId();
        $model->user_id = $review->userId();
        $model->rating = $review->rating()->value;
        $model->comment = $review->comment();
        $model->save();
    }

    public function summaryForRecipe(string $recipeId): array
    {
        $row = RecipeReviewModel::query()
            ->where('recipe_id', $recipeId)
            ->selectRaw('AVG(rating) as average, COUNT(*) as count')
            ->first();

        return [
            'average' => (float) ($row->average ?? 0),
            'count' => (int) ($row->count ?? 0),
        ];
    }

    private function toDomain(RecipeReviewModel $m): RecipeReview
    {
        return RecipeReview::create(
            id: $m->id,
            recipeId: $m->recipe_id,
            userId: $m->user_id,
            rating: new Rating($m->rating),
            comment: $m->comment,
            createdAt: DateTimeImmutable::createFromInterface($m->created_at),
        );
    }
}
