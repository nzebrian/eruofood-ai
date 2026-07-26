<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Application\Service;

use EruoFood\Marketplace\Application\Input\MenuItemInput;
use EruoFood\Marketplace\Application\Port\MenuDescriber;
use EruoFood\Marketplace\Domain\Exception\MarketplaceNotFound;
use EruoFood\Marketplace\Domain\Menu\MenuCategory;
use EruoFood\Marketplace\Domain\Menu\MenuCategoryRepository;
use EruoFood\Marketplace\Domain\Menu\MenuItem;
use EruoFood\Marketplace\Domain\Menu\MenuItemRepository;
use EruoFood\Marketplace\Domain\ValueObject\Promotion;

/**
 * Menu management: categories and items, availability, featuring, promotions,
 * inventory and AI-generated descriptions. All mutations run through the owning
 * vendor's authorisation ({@see VendorService::manageable()}).
 */
final readonly class MenuService
{
    public function __construct(
        private MenuItemRepository $items,
        private MenuCategoryRepository $categories,
        private VendorService $vendors,
        private MenuDescriber $describer,
    ) {
    }

    // ---- Categories -----------------------------------------------------

    /** @return list<MenuCategory> */
    public function categoriesFor(string $vendorId): array
    {
        return $this->categories->forVendor($vendorId);
    }

    public function createCategory(string $userId, bool $isAdmin, string $vendorId, string $name, int $sortOrder): MenuCategory
    {
        $this->vendors->manageable($userId, $isAdmin, $vendorId);
        $category = MenuCategory::create($this->categories->nextIdentity(), $vendorId, $name, $sortOrder);
        $this->categories->save($category);

        return $category;
    }

    public function deleteCategory(string $userId, bool $isAdmin, string $categoryId): void
    {
        $category = $this->categories->findById($categoryId) ?? throw MarketplaceNotFound::of('category', $categoryId);
        $this->vendors->manageable($userId, $isAdmin, $category->vendorId());
        $this->categories->delete($categoryId);
    }

    // ---- Items ----------------------------------------------------------

    /** @return list<MenuItem> */
    public function itemsFor(string $vendorId, bool $onlyAvailable = false): array
    {
        return $this->items->forVendor($vendorId, $onlyAvailable);
    }

    public function getItem(string $id): MenuItem
    {
        return $this->items->findById($id) ?? throw MarketplaceNotFound::of('menu item', $id);
    }

    public function createItem(string $userId, bool $isAdmin, string $vendorId, MenuItemInput $input): MenuItem
    {
        $this->vendors->manageable($userId, $isAdmin, $vendorId);

        $item = MenuItem::create(
            id: $this->items->nextIdentity(),
            vendorId: $vendorId,
            categoryId: $input->categoryId,
            name: $input->name,
            description: $input->description,
            basePrice: $input->basePrice,
            variants: $input->variants,
            images: $input->images,
            tags: $input->tags,
            trackInventory: $input->trackInventory,
            stock: $input->stock,
            calories: $input->calories,
            nutritionItemId: $input->nutritionItemId,
        );
        $this->items->save($item);

        return $item;
    }

    public function updateItem(string $userId, bool $isAdmin, string $itemId, MenuItemInput $input): MenuItem
    {
        $item = $this->manageableItem($userId, $isAdmin, $itemId);
        $item->update(
            $input->categoryId, $input->name, $input->description, $input->basePrice,
            $input->variants, $input->images, $input->tags, $input->calories, $input->nutritionItemId,
        );
        $this->items->save($item);

        return $item;
    }

    public function deleteItem(string $userId, bool $isAdmin, string $itemId): void
    {
        $this->manageableItem($userId, $isAdmin, $itemId);
        $this->items->delete($itemId);
    }

    public function setAvailability(string $userId, bool $isAdmin, string $itemId, bool $available): MenuItem
    {
        $item = $this->manageableItem($userId, $isAdmin, $itemId);
        $item->setAvailability($available);
        $this->items->save($item);

        return $item;
    }

    public function setFeatured(string $userId, bool $isAdmin, string $itemId, bool $featured): MenuItem
    {
        $item = $this->manageableItem($userId, $isAdmin, $itemId);
        $item->setFeatured($featured);
        $this->items->save($item);

        return $item;
    }

    public function setPromotion(string $userId, bool $isAdmin, string $itemId, ?Promotion $promotion): MenuItem
    {
        $item = $this->manageableItem($userId, $isAdmin, $itemId);
        $item->setPromotion($promotion);
        $this->items->save($item);

        return $item;
    }

    public function restock(string $userId, bool $isAdmin, string $itemId, int $stock): MenuItem
    {
        $item = $this->manageableItem($userId, $isAdmin, $itemId);
        $item->restock($stock);
        $this->items->save($item);

        return $item;
    }

    /** Generate and store an AI description for the item. */
    public function generateDescription(string $userId, bool $isAdmin, string $itemId): MenuItem
    {
        $item = $this->manageableItem($userId, $isAdmin, $itemId);
        $vendor = $this->vendors->get($item->vendorId());

        $text = $this->describer->describe($vendor->name(), $item->name(), $vendor->category(), $item->tags(), $userId);
        $item->setAiDescription($text);
        $this->items->save($item);

        return $item;
    }

    private function manageableItem(string $userId, bool $isAdmin, string $itemId): MenuItem
    {
        $item = $this->getItem($itemId);
        $this->vendors->manageable($userId, $isAdmin, $item->vendorId());

        return $item;
    }
}
