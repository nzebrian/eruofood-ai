<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Domain\Catalog;

use EruoFood\Commerce\Domain\Enum\GroceryDepartment;
use EruoFood\Commerce\Domain\Enum\ProductKind;
use EruoFood\Shared\Domain\ValueObject\Slug;

/**
 * A product category. Categories form a shallow tree (optional parent) and are
 * typed by {@see ProductKind}; grocery categories carry a {@see GroceryDepartment}
 * so the grocery catalogue can be organised into produce, pantry, beverages,
 * frozen and household aisles.
 */
final class Category
{
    private function __construct(
        private readonly string $id,
        private string $name,
        private Slug $slug,
        private ProductKind $kind,
        private ?GroceryDepartment $department,
        private ?string $parentId,
        private int $sortOrder,
    ) {
    }

    public static function create(
        string $id,
        string $name,
        Slug $slug,
        ProductKind $kind,
        ?GroceryDepartment $department = null,
        ?string $parentId = null,
        int $sortOrder = 0,
    ): self {
        return new self($id, $name, $slug, $kind, $department, $parentId, $sortOrder);
    }

    public static function reconstitute(
        string $id,
        string $name,
        Slug $slug,
        ProductKind $kind,
        ?GroceryDepartment $department,
        ?string $parentId,
        int $sortOrder,
    ): self {
        return new self($id, $name, $slug, $kind, $department, $parentId, $sortOrder);
    }

    public function rename(string $name): void
    {
        $this->name = $name;
    }

    public function reorder(int $sortOrder): void
    {
        $this->sortOrder = $sortOrder;
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

    public function kind(): ProductKind
    {
        return $this->kind;
    }

    public function department(): ?GroceryDepartment
    {
        return $this->department;
    }

    public function parentId(): ?string
    {
        return $this->parentId;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }
}
