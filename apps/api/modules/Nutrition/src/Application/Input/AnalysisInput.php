<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Application\Input;

/**
 * Validated input for meal / recipe nutrition analysis: a list of nutrition-database
 * items with the number of servings of each.
 */
final readonly class AnalysisInput
{
    /**
     * @param list<array{id: string, servings: float}> $items
     */
    public function __construct(public array $items)
    {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $items = [];
        foreach ($data['items'] ?? [] as $row) {
            $items[] = [
                'id' => (string) $row['nutrition_item_id'],
                'servings' => (float) ($row['servings'] ?? 1),
            ];
        }

        return new self(array_values($items));
    }
}
