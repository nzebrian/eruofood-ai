<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Application\Input;

use EruoFood\Nutrition\Domain\ValueObject\NutritionFacts;
use EruoFood\Nutrition\Domain\ValueObject\ServingSize;

/** Validated input for creating/updating a nutrition-database item. */
final readonly class NutritionItemInput
{
    public function __construct(
        public string $name,
        public string $category,
        public ServingSize $servingSize,
        public NutritionFacts $facts,
        public ?string $foodId,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed> $serving */
        $serving = $data['serving_size'] ?? [];

        return new self(
            name: (string) $data['name'],
            category: (string) ($data['category'] ?? 'general'),
            servingSize: new ServingSize(
                label: (string) ($serving['label'] ?? '1 serving'),
                grams: (float) ($serving['grams'] ?? 100),
            ),
            facts: NutritionFacts::fromArray((array) ($data['nutrition'] ?? [])),
            foodId: isset($data['food_id']) && $data['food_id'] !== '' ? (string) $data['food_id'] : null,
        );
    }
}
