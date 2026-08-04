<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Domain\Recipe;

/**
 * Stores immutable snapshots of a recipe at each version (recipe versioning).
 * A snapshot is an opaque payload captured whenever the recipe content changes.
 */
interface RecipeVersionRepository
{
    /**
     * @param array<string, mixed> $snapshot
     */
    public function record(string $recipeId, int $version, array $snapshot): void;

    /**
     * @return list<array{version: int, snapshot: array<string, mixed>, created_at: string}>
     */
    public function history(string $recipeId): array;
}
