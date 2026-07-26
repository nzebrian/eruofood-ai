<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Domain\ValueObject;

/**
 * Daily macronutrient targets in grams, derived from a calorie target and a
 * goal-specific split (protein & carbs at 4 kcal/g, fat at 9 kcal/g).
 */
final readonly class MacroTargets
{
    public function __construct(
        public int $proteinGrams,
        public int $carbGrams,
        public int $fatGrams,
    ) {
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'protein_grams' => $this->proteinGrams,
            'carb_grams' => $this->carbGrams,
            'fat_grams' => $this->fatGrams,
        ];
    }
}
