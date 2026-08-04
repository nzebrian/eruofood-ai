<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Application\Service;

use EruoFood\Catalog\Domain\Category\Category;
use EruoFood\Catalog\Domain\Category\CategoryRepository;
use EruoFood\Catalog\Domain\Enum\CategoryType;
use EruoFood\Catalog\Domain\Exception\CatalogNotFound;

/** Category management use cases (admin) + public listing. */
final readonly class CategoryService
{
    public function __construct(private CategoryRepository $categories)
    {
    }

    /** @return list<Category> */
    public function list(bool $onlyActive = true): array
    {
        return $this->categories->all($onlyActive);
    }

    public function create(string $name, CategoryType $type, ?string $description, int $sortOrder): Category
    {
        $category = Category::create($this->categories->nextIdentity(), $name, $type, $description, $sortOrder);
        $this->categories->save($category);

        return $category;
    }

    public function update(string $id, string $name, CategoryType $type, ?string $description, int $sortOrder): Category
    {
        $category = $this->load($id);
        $category->update($name, $description, $type, $sortOrder);
        $this->categories->save($category);

        return $category;
    }

    public function setActive(string $id, bool $active): Category
    {
        $category = $this->load($id);
        $active ? $category->activate() : $category->deactivate();
        $this->categories->save($category);

        return $category;
    }

    public function delete(string $id): void
    {
        $this->load($id);
        $this->categories->delete($id);
    }

    private function load(string $id): Category
    {
        return $this->categories->findById($id) ?? throw CatalogNotFound::of('category', $id);
    }
}
