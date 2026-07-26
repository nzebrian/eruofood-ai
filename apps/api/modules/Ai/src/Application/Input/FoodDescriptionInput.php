<?php

declare(strict_types=1);

namespace EruoFood\Ai\Application\Input;

/** Validated input for food description generation. */
final readonly class FoodDescriptionInput
{
    /** @param list<string> $keywords */
    public function __construct(
        public string $foodName,
        public ?string $region,
        public array $keywords,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            foodName: (string) $data['food_name'],
            region: isset($data['region']) ? (string) $data['region'] : null,
            keywords: array_values(array_map('strval', $data['keywords'] ?? [])),
        );
    }
}
