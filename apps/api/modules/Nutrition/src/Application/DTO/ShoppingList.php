<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Application\DTO;

/**
 * A shopping list generated from a meal plan: one line per distinct item with
 * the total servings needed and, when available, an estimated cost.
 */
final readonly class ShoppingList
{
    /**
     * @param list<array{name: string, servings: float, estimated_cost: float|null}> $items
     */
    public function __construct(
        public array $items,
        public float $totalEstimatedCost,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'items' => $this->items,
            'total_estimated_cost' => round($this->totalEstimatedCost, 2),
        ];
    }
}
