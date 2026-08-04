<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Domain\Menu;

/** A grouping of menu items within a vendor's storefront (e.g. "Rice", "Grills"). */
final class MenuCategory
{
    private function __construct(
        private readonly string $id,
        private readonly string $vendorId,
        private string $name,
        private int $sortOrder,
    ) {
    }

    public static function create(string $id, string $vendorId, string $name, int $sortOrder = 0): self
    {
        return new self($id, $vendorId, $name, $sortOrder);
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

    public function vendorId(): string
    {
        return $this->vendorId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }
}
