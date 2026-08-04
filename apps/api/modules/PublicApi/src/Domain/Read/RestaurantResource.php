<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\Read;

/** Public view of a restaurant/vendor — decoupled from Marketplace's Vendor entity. */
final readonly class RestaurantResource
{
    /** @param list<string> $images */
    public function __construct(
        public string $id,
        public string $slug,
        public string $name,
        public string $type,
        public string $category,
        public ?string $description,
        public bool $featured,
        public array $images,
    ) {
    }
}
