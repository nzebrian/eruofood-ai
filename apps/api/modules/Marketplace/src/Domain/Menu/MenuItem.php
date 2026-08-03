<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Domain\Menu;

use EruoFood\Marketplace\Domain\Exception\MarketplaceInvalidState;
use EruoFood\Marketplace\Domain\ValueObject\MenuVariant;
use EruoFood\Marketplace\Domain\ValueObject\Promotion;
use EruoFood\Shared\Domain\Exception\InvalidArgumentException;
use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * A sellable product on a vendor's menu — a food item (or market product).
 *
 * Owns pricing (base price + variant deltas + optional promotion), availability,
 * optional inventory tracking, images, an optional AI-generated description, and
 * an optional soft link to the Nutrition module for nutritional information.
 */
final class MenuItem
{
    /**
     * @param list<MenuVariant> $variants
     * @param list<string> $images
     * @param list<string> $tags
     */
    private function __construct(
        private readonly string $id,
        private readonly string $vendorId,
        private ?string $categoryId,
        private string $name,
        private ?string $description,
        private bool $descriptionIsAiGenerated,
        private Money $basePrice,
        private array $variants,
        private bool $available,
        private array $images,
        private array $tags,
        private bool $featured,
        private ?Promotion $promotion,
        private bool $trackInventory,
        private int $stock,
        private ?int $calories,
        private ?string $nutritionItemId,
    ) {
    }

    /**
     * @param list<MenuVariant> $variants
     * @param list<string> $images
     * @param list<string> $tags
     */
    public static function create(
        string $id,
        string $vendorId,
        ?string $categoryId,
        string $name,
        ?string $description,
        Money $basePrice,
        array $variants = [],
        array $images = [],
        array $tags = [],
        bool $trackInventory = false,
        int $stock = 0,
        ?int $calories = null,
        ?string $nutritionItemId = null,
    ): self {
        if ($basePrice->minorUnits < 0) {
            throw new InvalidArgumentException('Price cannot be negative.');
        }

        return new self(
            $id,
            $vendorId,
            $categoryId,
            $name,
            $description,
            false,
            $basePrice,
            array_values($variants),
            true,
            array_values($images),
            array_values($tags),
            false,
            null,
            $trackInventory,
            max(0, $stock),
            $calories,
            $nutritionItemId,
        );
    }

    /**
     * @param list<MenuVariant> $variants
     * @param list<string> $images
     * @param list<string> $tags
     */
    public static function reconstitute(
        string $id,
        string $vendorId,
        ?string $categoryId,
        string $name,
        ?string $description,
        bool $descriptionIsAiGenerated,
        Money $basePrice,
        array $variants,
        bool $available,
        array $images,
        array $tags,
        bool $featured,
        ?Promotion $promotion,
        bool $trackInventory,
        int $stock,
        ?int $calories,
        ?string $nutritionItemId,
    ): self {
        return new self(
            $id,
            $vendorId,
            $categoryId,
            $name,
            $description,
            $descriptionIsAiGenerated,
            $basePrice,
            array_values($variants),
            $available,
            array_values($images),
            array_values($tags),
            $featured,
            $promotion,
            $trackInventory,
            $stock,
            $calories,
            $nutritionItemId,
        );
    }

    /**
     * @param list<MenuVariant> $variants
     * @param list<string> $images
     * @param list<string> $tags
     */
    public function update(
        ?string $categoryId,
        string $name,
        ?string $description,
        Money $basePrice,
        array $variants,
        array $images,
        array $tags,
        ?int $calories,
        ?string $nutritionItemId,
    ): void {
        if ($basePrice->minorUnits < 0) {
            throw new InvalidArgumentException('Price cannot be negative.');
        }
        $this->categoryId = $categoryId;
        $this->name = $name;
        $this->description = $description;
        $this->descriptionIsAiGenerated = false;
        $this->basePrice = $basePrice;
        $this->variants = array_values($variants);
        $this->images = array_values($images);
        $this->tags = array_values($tags);
        $this->calories = $calories;
        $this->nutritionItemId = $nutritionItemId;
    }

    public function setAiDescription(string $description): void
    {
        $this->description = $description;
        $this->descriptionIsAiGenerated = true;
    }

    public function setAvailability(bool $available): void
    {
        $this->available = $available;
    }

    public function setFeatured(bool $featured): void
    {
        $this->featured = $featured;
    }

    public function setPromotion(?Promotion $promotion): void
    {
        $this->promotion = $promotion;
    }

    public function restock(int $stock): void
    {
        $this->trackInventory = true;
        $this->stock = max(0, $stock);
    }

    /** Reduce inventory when an order is placed (no-op if not tracking). */
    public function reduceStock(int $quantity): void
    {
        if (! $this->trackInventory) {
            return;
        }
        if ($quantity > $this->stock) {
            throw new MarketplaceInvalidState(sprintf('Not enough stock for "%s".', $this->name));
        }
        $this->stock -= $quantity;
    }

    public function isOrderable(): bool
    {
        return $this->available && (! $this->trackInventory || $this->stock > 0);
    }

    /** Effective unit price for a variant (base + delta, then promotion). */
    public function priceFor(?string $variantName): Money
    {
        $price = $this->basePrice;

        if ($variantName !== null) {
            $variant = $this->variant($variantName)
                ?? throw new MarketplaceInvalidState(sprintf('Unknown variant "%s".', $variantName));
            $price = $price->add($variant->priceDelta);
        }

        return $this->promotion?->applyTo($price) ?? $price;
    }

    public function variant(string $name): ?MenuVariant
    {
        foreach ($this->variants as $variant) {
            if ($variant->name === $name) {
                return $variant;
            }
        }

        return null;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function vendorId(): string
    {
        return $this->vendorId;
    }

    public function categoryId(): ?string
    {
        return $this->categoryId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function descriptionIsAiGenerated(): bool
    {
        return $this->descriptionIsAiGenerated;
    }

    public function basePrice(): Money
    {
        return $this->basePrice;
    }

    /** @return list<MenuVariant> */
    public function variants(): array
    {
        return $this->variants;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    /** @return list<string> */
    public function images(): array
    {
        return $this->images;
    }

    /** @return list<string> */
    public function tags(): array
    {
        return $this->tags;
    }

    public function isFeatured(): bool
    {
        return $this->featured;
    }

    public function promotion(): ?Promotion
    {
        return $this->promotion;
    }

    public function tracksInventory(): bool
    {
        return $this->trackInventory;
    }

    public function stock(): int
    {
        return $this->stock;
    }

    public function calories(): ?int
    {
        return $this->calories;
    }

    public function nutritionItemId(): ?string
    {
        return $this->nutritionItemId;
    }
}
