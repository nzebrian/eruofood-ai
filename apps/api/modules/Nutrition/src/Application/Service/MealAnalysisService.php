<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Application\Service;

use EruoFood\Nutrition\Application\DTO\MealAnalysis;
use EruoFood\Nutrition\Application\Input\AnalysisInput;
use EruoFood\Nutrition\Domain\Exception\NutritionNotFound;
use EruoFood\Nutrition\Domain\Item\NutritionItemRepository;
use EruoFood\Nutrition\Domain\ValueObject\NutritionFacts;

/**
 * Meal nutrition analysis and recipe nutrition breakdown: sum the scaled
 * nutrition of a set of database items and return totals + a per-item breakdown.
 */
final readonly class MealAnalysisService
{
    public function __construct(private NutritionItemRepository $items)
    {
    }

    public function analyse(AnalysisInput $input): MealAnalysis
    {
        $ids = array_map(static fn (array $i): string => $i['id'], $input->items);
        $found = $this->items->findMany($ids);

        $totals = NutritionFacts::empty();
        $breakdown = [];

        foreach ($input->items as $row) {
            $item = $found[$row['id']] ?? throw NutritionNotFound::of('nutrition item', $row['id']);
            $facts = $item->factsForServings($row['servings']);
            $totals = $totals->add($facts);
            $breakdown[] = [
                'name' => $item->name(),
                'servings' => $row['servings'],
                'facts' => $facts,
            ];
        }

        return new MealAnalysis($totals, $breakdown);
    }
}
