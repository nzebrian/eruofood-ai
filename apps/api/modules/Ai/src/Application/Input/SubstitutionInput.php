<?php

declare(strict_types=1);

namespace EruoFood\Ai\Application\Input;

/** Validated input for ingredient substitution. */
final readonly class SubstitutionInput
{
    /** @param list<string> $dietaryPreferences */
    public function __construct(
        public string $ingredient,
        public ?string $reason,
        public ?string $dishContext,
        public array $dietaryPreferences,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            ingredient: (string) $data['ingredient'],
            reason: isset($data['reason']) ? (string) $data['reason'] : null,
            dishContext: isset($data['dish_context']) ? (string) $data['dish_context'] : null,
            dietaryPreferences: array_values(array_map('strval', $data['dietary_preferences'] ?? [])),
        );
    }
}
