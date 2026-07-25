<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Domain\Recipe;

use EruoFood\Catalog\Domain\Enum\ContentStatus;
use EruoFood\Catalog\Domain\Enum\Difficulty;

/** Immutable filter describing a recipe search/browse query. */
final readonly class RecipeSearchCriteria
{
    public function __construct(
        public ?string $term = null,
        public ?string $foodId = null,
        public ?Difficulty $difficulty = null,
        public ?string $tag = null,
        public ?int $maxTotalMinutes = null,
        public ?string $authorId = null,
        public ?ContentStatus $status = ContentStatus::Published,
        public string $sort = 'recent',
    ) {
    }
}
