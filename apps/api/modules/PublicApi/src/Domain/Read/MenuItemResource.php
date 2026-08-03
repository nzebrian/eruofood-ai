<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\Read;

/** Public view of a restaurant menu item. */
final readonly class MenuItemResource
{
    /** @param list<string> $tags */
    public function __construct(
        public string $id,
        public string $restaurantId,
        public ?string $categoryId,
        public string $name,
        public ?string $description,
        public int $priceMinor,
        public string $currency,
        public bool $available,
        public array $tags,
    ) {
    }
}
