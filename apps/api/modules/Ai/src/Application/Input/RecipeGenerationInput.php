<?php

declare(strict_types=1);

namespace EruoFood\Ai\Application\Input;

/** Validated input for AI recipe generation. Built by the FormRequest. */
final readonly class RecipeGenerationInput
{
    /**
     * @param list<string> $dietaryPreferences
     * @param list<string> $availableIngredients
     */
    public function __construct(
        public string $dishName,
        public int $servings,
        public ?string $difficulty,
        public array $dietaryPreferences,
        public array $availableIngredients,
        public ?string $notes,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            dishName: (string) $data['dish_name'],
            servings: isset($data['servings']) ? (int) $data['servings'] : 4,
            difficulty: isset($data['difficulty']) ? (string) $data['difficulty'] : null,
            dietaryPreferences: array_values(array_map('strval', $data['dietary_preferences'] ?? [])),
            availableIngredients: array_values(array_map('strval', $data['available_ingredients'] ?? [])),
            notes: isset($data['notes']) ? (string) $data['notes'] : null,
        );
    }
}
