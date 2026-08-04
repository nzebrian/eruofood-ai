<?php

declare(strict_types=1);

namespace EruoFood\Ai\Application\Input;

/** Validated input for the leftover recipe generator. */
final readonly class LeftoverInput
{
    /**
     * @param list<string> $ingredients
     * @param list<string> $dietaryPreferences
     */
    public function __construct(
        public array $ingredients,
        public array $dietaryPreferences,
        public ?string $mealType,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            ingredients: array_values(array_map('strval', $data['ingredients'] ?? [])),
            dietaryPreferences: array_values(array_map('strval', $data['dietary_preferences'] ?? [])),
            mealType: isset($data['meal_type']) ? (string) $data['meal_type'] : null,
        );
    }
}
