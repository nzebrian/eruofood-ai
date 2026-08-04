<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Domain\Service;

use EruoFood\Nutrition\Domain\Enum\ActivityLevel;
use EruoFood\Nutrition\Domain\Enum\Gender;
use EruoFood\Nutrition\Domain\Enum\HealthGoal;
use EruoFood\Nutrition\Domain\Health\HealthProfile;
use EruoFood\Nutrition\Domain\ValueObject\MacroTargets;
use EruoFood\Nutrition\Domain\ValueObject\NutritionAssessment;
use EruoFood\Shared\Domain\Exception\InvalidArgumentException;

/**
 * Pure-domain nutrition mathematics. No framework, no I/O — just the standard,
 * well-documented formulae, so the numbers are auditable and unit-testable.
 *
 * - **BMI** = weight(kg) / height(m)²  (WHO categories).
 * - **BMR** via the **Mifflin-St Jeor** equation, the modern default:
 *     10·kg + 6.25·cm − 5·age + s, where s = +5 (male) / −161 (female);
 *     "other" averages the two constants (−78).
 * - **TDEE** = BMR × activity factor.
 * - **Calorie target** = TDEE + a goal adjustment, floored at a safe minimum.
 * - **Macro targets** split the calories per goal (protein/carbs 4 kcal·g⁻¹,
 *   fat 9 kcal·g⁻¹).
 */
final readonly class NutritionCalculator
{
    public function __construct(private CalculatorSettings $settings)
    {
    }

    public function bmi(float $weightKg, float $heightCm): float
    {
        if ($heightCm <= 0) {
            throw new InvalidArgumentException('Height must be greater than zero.');
        }
        $metres = $heightCm / 100;

        return $weightKg / ($metres * $metres);
    }

    public function bmiCategory(float $bmi): string
    {
        return match (true) {
            $bmi < 18.5 => 'underweight',
            $bmi < 25.0 => 'normal',
            $bmi < 30.0 => 'overweight',
            default => 'obese',
        };
    }

    /** Basal Metabolic Rate (kcal/day), Mifflin-St Jeor. */
    public function bmr(float $weightKg, float $heightCm, int $age, Gender $gender): float
    {
        $base = (10 * $weightKg) + (6.25 * $heightCm) - (5 * $age);

        $constant = match ($gender) {
            Gender::Male => 5,
            Gender::Female => -161,
            Gender::Other => -78, // average of the male/female constants
        };

        return $base + $constant;
    }

    /** Total Daily Energy Expenditure (kcal/day). */
    public function tdee(float $bmr, ActivityLevel $activity): float
    {
        return $bmr * $this->settings->activityFactor($activity);
    }

    /** Recommended daily calories for a goal, floored at the safe minimum. */
    public function calorieTarget(float $tdee, HealthGoal $goal): int
    {
        $target = (int) round($tdee + $this->settings->goalAdjustment($goal));

        return max($this->settings->minCalories, $target);
    }

    public function macroTargets(int $calories, HealthGoal $goal): MacroTargets
    {
        $split = $this->settings->macroSplit($goal);

        return new MacroTargets(
            proteinGrams: (int) round(($calories * $split['protein'] / 100) / 4),
            carbGrams: (int) round(($calories * $split['carbs'] / 100) / 4),
            fatGrams: (int) round(($calories * $split['fat'] / 100) / 9),
        );
    }

    /** Full assessment for a profile (BMI, BMR, TDEE, calorie + macro targets). */
    public function assess(HealthProfile $profile): NutritionAssessment
    {
        $bmi = $this->bmi($profile->weightKg(), $profile->heightCm());
        $bmr = $this->bmr($profile->weightKg(), $profile->heightCm(), $profile->age(), $profile->gender());
        $tdee = $this->tdee($bmr, $profile->activityLevel());
        $calories = $this->calorieTarget($tdee, $profile->goal());

        return new NutritionAssessment(
            bmi: $bmi,
            bmiCategory: $this->bmiCategory($bmi),
            bmr: (int) round($bmr),
            tdee: (int) round($tdee),
            calorieTarget: $calories,
            macroTargets: $this->macroTargets($calories, $profile->goal()),
        );
    }
}
