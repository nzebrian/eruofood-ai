<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Domain\Catalog;

use EruoFood\Commerce\Domain\Enum\GroceryDepartment;
use EruoFood\Commerce\Domain\Enum\ProductKind;
use EruoFood\Commerce\Domain\Enum\ProductStatus;
use EruoFood\Commerce\Domain\Event\ProductPublished;
use EruoFood\Commerce\Domain\ValueObject\Barcode;
use EruoFood\Commerce\Domain\ValueObject\ProductVariant;
use EruoFood\Shared\Domain\AggregateRoot;
use EruoFood\Shared\Domain\Exception\InvalidArgumentException;
use EruoFood\Shared\Domain\ValueObject\Money;
use EruoFood\Shared\Domain\ValueObject\Slug;

/**
 * A sellable product — the aggregate root over its variants, pricing, media and
 * moderation state. General goods and grocery lines share this aggregate,
 * distinguished by {@see ProductKind} (grocery products also carry a
 * {@see GroceryDepartment}). Only Published products are publicly listed.
 *
 * Pricing is base price + per-variant delta. An optional {@see Barcode} makes
 * the product barcode-scanning ready. Stock lives in the Inventory aggregate
 * (referenced by product/variant SKU) — never here — so catalogue edits and
 * warehouse movements stay independent.
 */
final class Product extends AggregateRoot
{
    /**
     * @param list<ProductVariant> $variants
     * @param list<string> $images
     * @param list<string> $tags
     */
    private function __construct(
        private readonly string $id,
        private readonly string $storeId,
        private ?string $categoryId,
        private string $name,
        private Slug $slug,
        private ProductKind $kind,
        private ?GroceryDepartment $department,
        private ?string $description,
        private bool $descriptionIsAiGenerated,
        private Money $basePrice,
        private array $variants,
        private array $images,
        private array $tags,
        private ProductStatus $status,
        private bool $featured,
        private ?Barcode $barcode,
        private ?string $brand,
        private float $ratingAverage,
        private int $ratingCount,
    ) {
    }

    /**
     * @param list<ProductVariant> $variants
     * @param list<string> $images
     * @param list<string> $tags
     */
    public static function create(
        string $id,
        string $storeId,
        ?string $categoryId,
        string $name,
        Slug $slug,
        ProductKind $kind,
        ?GroceryDepartment $department,
        ?string $description,
        Money $basePrice,
        array $variants = [],
        array $images = [],
        array $tags = [],
        ?Barcode $barcode = null,
        ?string $brand = null,
        bool $autoPublish = false,
    ): self {
        if ($basePrice->minorUnits < 0) {
            throw new InvalidArgumentException('Price cannot be negative.');
        }
        if ($kind === ProductKind::Grocery && $department === null) {
            $department = GroceryDepartment::Other;
        }

        $product = new self(
            $id, $storeId, $categoryId, $name, $slug, $kind, $department, $description,
            false, $basePrice, array_values($variants), array_values($images),
            array_values($tags), ProductStatus::Draft, false, $barcode, $brand, 0.0, 0,
        );
        if ($autoPublish) {
            $product->publish();
        }

        return $product;
    }

    /**
     * @param list<ProductVariant> $variants
     * @param list<string> $images
     * @param list<string> $tags
     */
    public static function reconstitute(
        string $id,
        string $storeId,
        ?string $categoryId,
        string $name,
        Slug $slug,
        ProductKind $kind,
        ?GroceryDepartment $department,
        ?string $description,
        bool $descriptionIsAiGenerated,
        Money $basePrice,
        array $variants,
        array $images,
        array $tags,
        ProductStatus $status,
        bool $featured,
        ?Barcode $barcode,
        ?string $brand,
        float $ratingAverage,
        int $ratingCount,
    ): self {
        return new self(
            $id, $storeId, $categoryId, $name, $slug, $kind, $department, $description,
            $descriptionIsAiGenerated, $basePrice, array_values($variants),
            array_values($images), array_values($tags), $status, $featured, $barcode,
            $brand, $ratingAverage, $ratingCount,
        );
    }

    /**
     * @param list<ProductVariant> $variants
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
        ?Barcode $barcode,
        ?string $brand,
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
        $this->barcode = $barcode;
        $this->brand = $brand;
    }

    public function submitForApproval(): void
    {
        if ($this->status === ProductStatus::Published) {
            return;
        }
        $this->status = ProductStatus::Pending;
    }

    public function publish(): void
    {
        $this->status = ProductStatus::Published;
        $this->recordThat(new ProductPublished($this->id, $this->storeId));
    }

    public function reject(): void
    {
        $this->status = ProductStatus::Rejected;
    }

    public function setAiDescription(string $description): void
    {
        $this->description = $description;
        $this->descriptionIsAiGenerated = true;
    }

    public function setFeatured(bool $featured): void
    {
        $this->featured = $featured;
    }

    public function applyRatingSummary(float $average, int $count): void
    {
        $this->ratingAverage = round($average, 2);
        $this->ratingCount = $count;
    }

    public function isOrderable(): bool
    {
        return $this->status->isPublic();
    }

    /** Effective unit price for a variant SKU (base + delta). */
    public function priceFor(?string $variantSku): Money
    {
        if ($variantSku === null) {
            return $this->basePrice;
        }
        $variant = $this->variant($variantSku)
            ?? throw new InvalidArgumentException(sprintf('Unknown variant "%s".', $variantSku));

        return $this->basePrice->add($variant->priceDelta);
    }

    public function variant(string $sku): ?ProductVariant
    {
        foreach ($this->variants as $variant) {
            if ($variant->sku === $sku) {
                return $variant;
            }
        }

        return null;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function storeId(): string
    {
        return $this->storeId;
    }

    public function categoryId(): ?string
    {
        return $this->categoryId;
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

    /** @return list<ProductVariant> */
    public function variants(): array
    {
        return $this->variants;
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

    public function status(): ProductStatus
    {
        return $this->status;
    }

    public function isFeatured(): bool
    {
        return $this->featured;
    }

    public function barcode(): ?Barcode
    {
        return $this->barcode;
    }

    public function brand(): ?string
    {
        return $this->brand;
    }

    public function ratingAverage(): float
    {
        return $this->ratingAverage;
    }

    public function ratingCount(): int
    {
        return $this->ratingCount;
    }
}
