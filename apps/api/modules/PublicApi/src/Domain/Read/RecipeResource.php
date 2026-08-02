<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\Read;

/** A public, transformer-ready view of a recipe — decoupled from Catalog's Recipe entity. */
final readonly class RecipeResource
{
    public function __construct(
        public string $id,
        public string $slug,
        public string $title,
        public ?string $summary,
        public ?int $prepMinutes,
        public ?int $cookMinutes,
        public ?string $difficulty,
        public ?string $updatedAt,
    ) {
    }
}
