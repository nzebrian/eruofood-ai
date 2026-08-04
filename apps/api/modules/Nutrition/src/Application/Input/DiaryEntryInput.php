<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Application\Input;

use EruoFood\Nutrition\Domain\Enum\MealType;
use EruoFood\Nutrition\Domain\ValueObject\NutritionFacts;

/**
 * Validated input for logging a diary entry. Either references a nutrition-database
 * item (`nutritionItemId` + servings — the service resolves and scales its facts)
 * or logs a custom food (`itemName` + `facts`).
 */
final readonly class DiaryEntryInput
{
    public function __construct(
        public string $date,
        public MealType $mealType,
        public float $servings,
        public ?string $nutritionItemId,
        public ?string $itemName,
        public ?NutritionFacts $facts,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            date: (string) $data['date'],
            mealType: MealType::from((string) $data['meal_type']),
            servings: (float) ($data['servings'] ?? 1),
            nutritionItemId: isset($data['nutrition_item_id']) && $data['nutrition_item_id'] !== ''
                ? (string) $data['nutrition_item_id']
                : null,
            itemName: isset($data['item_name']) ? (string) $data['item_name'] : null,
            facts: isset($data['nutrition']) ? NutritionFacts::fromArray((array) $data['nutrition']) : null,
        );
    }
}
