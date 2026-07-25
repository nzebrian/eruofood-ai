<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Domain\Food;

use EruoFood\Catalog\Domain\Enum\ContentStatus;
use EruoFood\Catalog\Domain\Enum\FoodRegion;

/** Immutable filter describing a food search/browse query. */
final readonly class FoodSearchCriteria
{
    public function __construct(
        public ?string $term = null,
        public ?string $categoryId = null,
        public ?FoodRegion $region = null,
        public ?string $state = null,
        public ?string $tag = null,
        public ?ContentStatus $status = ContentStatus::Published,
        public string $sort = 'name',
    ) {
    }
}
