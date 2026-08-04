<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Domain\Category;

use EruoFood\Catalog\Domain\Enum\CategoryType;
use EruoFood\Shared\Domain\ValueObject\Slug;

/** Category aggregate — a grouping of foods (Soups, Swallows, Rice, …). */
final class Category
{
    private function __construct(
        private readonly string $id,
        private string $name,
        private Slug $slug,
        private CategoryType $type,
        private ?string $description,
        private int $sortOrder,
        private bool $active,
    ) {
    }

    public static function create(
        string $id,
        string $name,
        CategoryType $type,
        ?string $description = null,
        int $sortOrder = 0,
    ): self {
        return new self($id, $name, Slug::fromTitle($name), $type, $description, $sortOrder, true);
    }

    public static function reconstitute(
        string $id,
        string $name,
        Slug $slug,
        CategoryType $type,
        ?string $description,
        int $sortOrder,
        bool $active,
    ): self {
        return new self($id, $name, $slug, $type, $description, $sortOrder, $active);
    }

    public function update(string $name, ?string $description, CategoryType $type, int $sortOrder): void
    {
        $this->name = $name;
        $this->slug = Slug::fromTitle($name);
        $this->description = $description;
        $this->type = $type;
        $this->sortOrder = $sortOrder;
    }

    public function activate(): void
    {
        $this->active = true;
    }

    public function deactivate(): void
    {
        $this->active = false;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function slug(): Slug
    {
        return $this->slug;
    }

    public function type(): CategoryType
    {
        return $this->type;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }

    public function isActive(): bool
    {
        return $this->active;
    }
}
