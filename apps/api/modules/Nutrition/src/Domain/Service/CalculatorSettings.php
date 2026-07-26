<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Domain\Service;

use EruoFood\Nutrition\Domain\Enum\ActivityLevel;
use EruoFood\Nutrition\Domain\Enum\HealthGoal;

/**
 * Tunable constants for the {@see NutritionCalculator}: activity multipliers,
 * per-goal calorie adjustments, per-goal macro splits, and a calorie floor.
 *
 * Passing these as a value object (built from config/nutrition.php by the
 * service provider) keeps the calculator a pure, framework-free domain service
 * while the numbers stay configurable and documented.
 */
final readonly class CalculatorSettings
{
    /**
     * @param array<string, float> $activityFactors keyed by ActivityLevel value
     * @param array<string, int> $goalAdjustments keyed by HealthGoal value
     * @param array<string, array{protein: int, carbs: int, fat: int}> $macroSplits keyed by HealthGoal value
     */
    public function __construct(
        public array $activityFactors,
        public array $goalAdjustments,
        public array $macroSplits,
        public int $minCalories = 1200,
    ) {
    }

    /** Sensible defaults derived from the enums (used when config is absent). */
    public static function defaults(): self
    {
        $factors = [];
        foreach (ActivityLevel::cases() as $level) {
            $factors[$level->value] = $level->defaultFactor();
        }

        $adjustments = [];
        foreach (HealthGoal::cases() as $goal) {
            $adjustments[$goal->value] = $goal->defaultCalorieAdjustment();
        }

        return new self(
            activityFactors: $factors,
            goalAdjustments: $adjustments,
            macroSplits: [
                'lose_weight' => ['protein' => 35, 'carbs' => 30, 'fat' => 35],
                'maintain' => ['protein' => 30, 'carbs' => 40, 'fat' => 30],
                'gain_weight' => ['protein' => 25, 'carbs' => 50, 'fat' => 25],
                'gain_muscle' => ['protein' => 35, 'carbs' => 40, 'fat' => 25],
            ],
            minCalories: 1200,
        );
    }

    public function activityFactor(ActivityLevel $level): float
    {
        return $this->activityFactors[$level->value] ?? $level->defaultFactor();
    }

    public function goalAdjustment(HealthGoal $goal): int
    {
        return $this->goalAdjustments[$goal->value] ?? $goal->defaultCalorieAdjustment();
    }

    /** @return array{protein: int, carbs: int, fat: int} */
    public function macroSplit(HealthGoal $goal): array
    {
        return $this->macroSplits[$goal->value] ?? ['protein' => 30, 'carbs' => 40, 'fat' => 30];
    }
}
