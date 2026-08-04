<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\Read;

/** Public view of a nutrition item. */
final readonly class NutritionResource
{
    /** @param array<string, mixed> $facts */
    public function __construct(
        public string $id,
        public string $slug,
        public string $name,
        public string $category,
        public ?string $foodId,
        public array $facts,
    ) {
    }
}
