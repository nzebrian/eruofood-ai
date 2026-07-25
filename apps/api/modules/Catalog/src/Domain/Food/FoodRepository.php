<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Domain\Food;

use EruoFood\Shared\Domain\Paginated;

interface FoodRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?Food;

    public function findBySlug(string $slug): ?Food;

    public function existsBySlug(string $slug): bool;

    /**
     * @return Paginated<Food>
     */
    public function search(FoodSearchCriteria $criteria, int $page, int $perPage): Paginated;

    public function save(Food $food): void;

    public function delete(string $id): void;
}
