<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Application\Service;

use EruoFood\Nutrition\Application\DTO\ShoppingList;
use EruoFood\Nutrition\Domain\Plan\MealPlanEntry;

/**
 * Generates a shopping list from a meal plan by collapsing its entries: distinct
 * dishes are merged, their servings summed, and any per-entry cost estimates
 * rolled up into a plan total.
 */
final readonly class ShoppingListService
{
    public function __construct(private MealPlanService $plans)
    {
    }

    public function generate(string $userId, string $planId): ShoppingList
    {
        $plan = $this->plans->get($userId, $planId);

        /** @var array<string, array{name: string, servings: float, estimated_cost: float|null}> $lines */
        $lines = [];
        foreach ($plan->entries() as $entry) {
            $key = $entry->nutritionItemId ?? mb_strtolower($entry->label);
            if (! isset($lines[$key])) {
                $lines[$key] = ['name' => $entry->label, 'servings' => 0.0, 'estimated_cost' => null];
            }
            $lines[$key]['servings'] += $entry->servings;
            $lines[$key]['estimated_cost'] = $this->addCost($lines[$key]['estimated_cost'], $entry);
        }

        $total = array_reduce(
            $lines,
            static fn (float $carry, array $line): float => $carry + ($line['estimated_cost'] ?? 0.0),
            0.0,
        );

        return new ShoppingList(array_values($lines), $total);
    }

    private function addCost(?float $current, MealPlanEntry $entry): ?float
    {
        if ($entry->estimatedCost === null) {
            return $current;
        }

        return ($current ?? 0.0) + $entry->estimatedCost;
    }
}
