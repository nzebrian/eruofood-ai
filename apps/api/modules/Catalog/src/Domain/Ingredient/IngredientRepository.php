<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Domain\Ingredient;

use EruoFood\Shared\Domain\Paginated;

interface IngredientRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?Ingredient;

    public function existsBySlug(string $slug): bool;

    /**
     * @return Paginated<Ingredient>
     */
    public function search(?string $term, int $page, int $perPage): Paginated;

    public function save(Ingredient $ingredient): void;

    public function delete(string $id): void;
}
