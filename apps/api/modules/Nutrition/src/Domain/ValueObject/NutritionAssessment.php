<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Domain\ValueObject;

/**
 * The computed nutritional picture for a person: body-mass index (+ category),
 * basal metabolic rate, total daily energy expenditure, a recommended daily
 * calorie target for their goal, and the macronutrient split to hit it.
 */
final readonly class NutritionAssessment
{
    public function __construct(
        public float $bmi,
        public string $bmiCategory,
        public int $bmr,
        public int $tdee,
        public int $calorieTarget,
        public MacroTargets $macroTargets,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'bmi' => round($this->bmi, 1),
            'bmi_category' => $this->bmiCategory,
            'bmr' => $this->bmr,
            'tdee' => $this->tdee,
            'calorie_target' => $this->calorieTarget,
            'macro_targets' => $this->macroTargets->toArray(),
        ];
    }
}
