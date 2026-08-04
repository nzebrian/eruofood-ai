<?php

declare(strict_types=1);

namespace EruoFood\Ai\Application\Input;

/** Validated input for meal suggestions. */
final readonly class MealSuggestionInput
{
    /** @param list<string> $dietaryPreferences */
    public function __construct(
        public string $mealType,
        public array $dietaryPreferences,
        public int $count,
        public ?string $budget,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            mealType: (string) ($data['meal_type'] ?? 'dinner'),
            dietaryPreferences: array_values(array_map('strval', $data['dietary_preferences'] ?? [])),
            count: isset($data['count']) ? max(1, min(10, (int) $data['count'])) : 3,
            budget: isset($data['budget']) ? (string) $data['budget'] : null,
        );
    }
}
