<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Domain\Category;

interface CategoryRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?Category;

    public function findBySlug(string $slug): ?Category;

    public function existsBySlug(string $slug): bool;

    /** @return list<Category> */
    public function all(bool $onlyActive = true): array;

    public function save(Category $category): void;

    public function delete(string $id): void;
}
