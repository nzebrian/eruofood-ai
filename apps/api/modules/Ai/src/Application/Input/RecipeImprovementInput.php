<?php

declare(strict_types=1);

namespace EruoFood\Ai\Application\Input;

/** Validated input for improving an existing recipe. */
final readonly class RecipeImprovementInput
{
    /**
     * @param list<string> $ingredients
     * @param list<string> $steps
     */
    public function __construct(
        public string $title,
        public array $ingredients,
        public array $steps,
        public string $goal,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            title: (string) $data['title'],
            ingredients: array_values(array_map('strval', $data['ingredients'] ?? [])),
            steps: array_values(array_map('strval', $data['steps'] ?? [])),
            goal: (string) ($data['goal'] ?? 'make it tastier'),
        );
    }
}
