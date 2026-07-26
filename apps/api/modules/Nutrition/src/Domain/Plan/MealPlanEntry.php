<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Domain\Plan;

use EruoFood\Nutrition\Domain\Enum\MealType;
use EruoFood\Shared\Domain\Exception\InvalidArgumentException;

/** One slot in a meal plan: a dish for a given date + meal, with a portion. */
final readonly class MealPlanEntry
{
    public function __construct(
        public string $date, // Y-m-d
        public MealType $mealType,
        public string $label,
        public float $servings,
        public ?string $nutritionItemId = null,
        public ?float $estimatedCost = null,
    ) {
        if ($servings <= 0) {
            throw new InvalidArgumentException('Meal plan servings must be greater than zero.');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new InvalidArgumentException('Meal plan entry date must be an ISO Y-m-d string.');
        }
    }

    /** A copy with the portion scaled (for portion adjustment). */
    public function withServings(float $servings): self
    {
        return new self(
            $this->date,
            $this->mealType,
            $this->label,
            $servings,
            $this->nutritionItemId,
            $this->estimatedCost === null ? null : $this->estimatedCost * ($servings / $this->servings),
        );
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            date: (string) $data['date'],
            mealType: MealType::from((string) $data['meal_type']),
            label: (string) $data['label'],
            servings: (float) ($data['servings'] ?? 1),
            nutritionItemId: isset($data['nutrition_item_id']) ? (string) $data['nutrition_item_id'] : null,
            estimatedCost: isset($data['estimated_cost']) ? (float) $data['estimated_cost'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'date' => $this->date,
            'meal_type' => $this->mealType->value,
            'label' => $this->label,
            'servings' => $this->servings,
            'nutrition_item_id' => $this->nutritionItemId,
            'estimated_cost' => $this->estimatedCost,
        ];
    }
}
