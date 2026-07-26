<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Application\DTO;

use EruoFood\Nutrition\Domain\ValueObject\NutritionFacts;

/**
 * The result of analysing a set of foods (a meal or a recipe's ingredients):
 * summed totals plus a per-item breakdown.
 */
final readonly class MealAnalysis
{
    /**
     * @param list<array{name: string, servings: float, facts: NutritionFacts}> $items
     */
    public function __construct(
        public NutritionFacts $totals,
        public array $items,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'totals' => $this->totals->toArray(),
            'items' => array_map(
                static fn (array $i): array => [
                    'name' => $i['name'],
                    'servings' => $i['servings'],
                    'facts' => $i['facts']->toArray(),
                ],
                $this->items,
            ),
        ];
    }
}
