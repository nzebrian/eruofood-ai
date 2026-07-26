<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Domain\Item;

use EruoFood\Shared\Domain\Paginated;

/** Persistence port for the nutrition database (Repository Pattern). */
interface NutritionItemRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?NutritionItem;

    public function slugExists(string $slug): bool;

    /**
     * @param list<string> $ids
     * @return array<string, NutritionItem> keyed by id (missing ids omitted)
     */
    public function findMany(array $ids): array;

    /**
     * @return Paginated<NutritionItem>
     */
    public function search(?string $term, ?string $category, int $page, int $perPage): Paginated;

    public function save(NutritionItem $item): void;

    public function delete(string $id): void;
}
