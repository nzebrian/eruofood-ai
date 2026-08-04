<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Infrastructure\Persistence\Eloquent;

use EruoFood\Catalog\Domain\Recipe\RecipeVersionRepository;
use EruoFood\Catalog\Infrastructure\Persistence\Eloquent\Model\RecipeVersionModel;
use Illuminate\Support\Str;

final class EloquentRecipeVersionRepository implements RecipeVersionRepository
{
    public function record(string $recipeId, int $version, array $snapshot): void
    {
        RecipeVersionModel::query()->updateOrCreate(
            ['recipe_id' => $recipeId, 'version' => $version],
            ['id' => (string) Str::orderedUuid(), 'snapshot' => $snapshot],
        );
    }

    public function history(string $recipeId): array
    {
        return array_values(RecipeVersionModel::query()
            ->where('recipe_id', $recipeId)
            ->orderByDesc('version')
            ->get()
            ->map(static fn (RecipeVersionModel $m): array => [
                'version' => $m->version,
                'snapshot' => $m->snapshot,
                'created_at' => $m->created_at->toAtomString(),
            ])
            ->all());
    }
}
