<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Application\Input;

use EruoFood\Marketplace\Domain\ValueObject\MenuVariant;
use EruoFood\Shared\Domain\ValueObject\Money;

/** Validated input for creating/updating a menu item. */
final readonly class MenuItemInput
{
    /**
     * @param list<MenuVariant> $variants
     * @param list<string> $images
     * @param list<string> $tags
     */
    public function __construct(
        public ?string $categoryId,
        public string $name,
        public ?string $description,
        public Money $basePrice,
        public array $variants,
        public array $images,
        public array $tags,
        public bool $trackInventory,
        public int $stock,
        public ?int $calories,
        public ?string $nutritionItemId,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, string $currency): self
    {
        $variants = array_map(
            static fn (array $v): MenuVariant => MenuVariant::fromArray($v, $currency),
            $data['variants'] ?? [],
        );

        return new self(
            categoryId: isset($data['category_id']) && $data['category_id'] !== '' ? (string) $data['category_id'] : null,
            name: (string) $data['name'],
            description: isset($data['description']) ? (string) $data['description'] : null,
            basePrice: new Money((int) $data['base_price_minor'], $currency),
            variants: array_values($variants),
            images: array_values(array_map('strval', $data['images'] ?? [])),
            tags: array_values(array_map('strval', $data['tags'] ?? [])),
            trackInventory: (bool) ($data['track_inventory'] ?? false),
            stock: (int) ($data['stock'] ?? 0),
            calories: isset($data['calories']) ? (int) $data['calories'] : null,
            nutritionItemId: isset($data['nutrition_item_id']) && $data['nutrition_item_id'] !== ''
                ? (string) $data['nutrition_item_id'] : null,
        );
    }
}
