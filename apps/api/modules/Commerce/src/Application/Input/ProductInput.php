<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Application\Input;

use EruoFood\Commerce\Domain\Enum\GroceryDepartment;
use EruoFood\Commerce\Domain\Enum\ProductKind;
use EruoFood\Commerce\Domain\ValueObject\Barcode;
use EruoFood\Commerce\Domain\ValueObject\ProductVariant;
use EruoFood\Shared\Domain\ValueObject\Money;

/** Validated input for creating/updating a product. */
final readonly class ProductInput
{
    /**
     * @param list<ProductVariant> $variants
     * @param list<string> $images
     * @param list<string> $tags
     */
    public function __construct(
        public ?string $categoryId,
        public string $name,
        public ProductKind $kind,
        public ?GroceryDepartment $department,
        public ?string $description,
        public Money $basePrice,
        public array $variants,
        public array $images,
        public array $tags,
        public ?Barcode $barcode,
        public ?string $brand,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, string $currency): self
    {
        $variants = array_map(
            static fn (array $v): ProductVariant => ProductVariant::fromArray($v, $currency),
            $data['variants'] ?? [],
        );

        $kind = ProductKind::from((string) ($data['kind'] ?? ProductKind::General->value));
        $department = null;
        if (isset($data['department']) && $data['department'] !== '') {
            $department = GroceryDepartment::from((string) $data['department']);
        }

        return new self(
            categoryId: isset($data['category_id']) && $data['category_id'] !== '' ? (string) $data['category_id'] : null,
            name: (string) $data['name'],
            kind: $kind,
            department: $department,
            description: isset($data['description']) ? (string) $data['description'] : null,
            basePrice: new Money((int) $data['base_price_minor'], $currency),
            variants: array_values($variants),
            images: array_values(array_map('strval', $data['images'] ?? [])),
            tags: array_values(array_map('strval', $data['tags'] ?? [])),
            barcode: isset($data['barcode']) && $data['barcode'] !== '' ? new Barcode((string) $data['barcode']) : null,
            brand: isset($data['brand']) && $data['brand'] !== '' ? (string) $data['brand'] : null,
        );
    }
}
