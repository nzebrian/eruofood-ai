<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Application\Input;

use EruoFood\Catalog\Domain\Enum\Difficulty;
use EruoFood\Catalog\Domain\ValueObject\CookingStep;
use EruoFood\Catalog\Domain\ValueObject\RecipeIngredient;

/** Validated input for creating/updating a Recipe. Built by the FormRequest. */
final readonly class RecipeInput
{
    /**
     * @param list<RecipeIngredient> $ingredients
     * @param list<CookingStep> $steps
     * @param list<string> $tips
     * @param list<string> $tags
     */
    public function __construct(
        public string $foodId,
        public string $title,
        public int $prepTimeMinutes,
        public int $cookTimeMinutes,
        public Difficulty $difficulty,
        public int $servingSize,
        public array $ingredients,
        public array $steps,
        public ?string $summary = null,
        public array $tips = [],
        public array $tags = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $ingredients = array_map(
            static fn (array $i): RecipeIngredient => RecipeIngredient::fromArray($i),
            $data['ingredients'] ?? [],
        );
        $steps = array_map(
            static fn (array $s): CookingStep => CookingStep::fromArray($s),
            $data['steps'] ?? [],
        );

        return new self(
            foodId: (string) $data['food_id'],
            title: (string) $data['title'],
            prepTimeMinutes: (int) $data['prep_time_minutes'],
            cookTimeMinutes: (int) $data['cook_time_minutes'],
            difficulty: Difficulty::from((string) $data['difficulty']),
            servingSize: (int) $data['serving_size'],
            ingredients: array_values($ingredients),
            steps: array_values($steps),
            summary: isset($data['summary']) ? (string) $data['summary'] : null,
            tips: array_values(array_map('strval', $data['tips'] ?? [])),
            tags: array_values(array_map('strval', $data['tags'] ?? [])),
        );
    }
}
