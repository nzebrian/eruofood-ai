<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\Read;

/** A public, transformer-ready view of a food — decoupled from Catalog's Food entity. */
final readonly class FoodResource
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public string $id,
        public string $slug,
        public string $name,
        public ?string $description,
        public ?string $region,
        public ?string $imageUrl,
        public array $tags,
        public ?string $updatedAt,
    ) {
    }
}
