<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Domain\Recipe;

use EruoFood\Shared\Domain\Paginated;

interface RecipeRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?Recipe;

    public function findBySlug(string $slug): ?Recipe;

    public function existsBySlug(string $slug): bool;

    /**
     * @return Paginated<Recipe>
     */
    public function search(RecipeSearchCriteria $criteria, int $page, int $perPage): Paginated;

    /**
     * @param list<string> $ids
     * @return list<Recipe>
     */
    public function findManyByIds(array $ids): array;

    public function save(Recipe $recipe): void;

    public function delete(string $id): void;
}
