<?php

declare(strict_types=1);

use EruoFood\Nutrition\Domain\Enum\ActivityLevel;
use EruoFood\Nutrition\Domain\Enum\Gender;
use EruoFood\Nutrition\Domain\Enum\HealthGoal;
use EruoFood\Nutrition\Domain\Service\CalculatorSettings;
use EruoFood\Nutrition\Domain\Service\NutritionCalculator;

beforeEach(function (): void {
    $this->calc = new NutritionCalculator(CalculatorSettings::defaults());
});

it('computes BMI and classifies it', function (): void {
    // 80 kg, 180 cm => BMI 24.69 (normal)
    expect(round($this->calc->bmi(80, 180), 2))->toBe(24.69)
        ->and($this->calc->bmiCategory(24.69))->toBe('normal')
        ->and($this->calc->bmiCategory(17.0))->toBe('underweight')
        ->and($this->calc->bmiCategory(27.0))->toBe('overweight')
        ->and($this->calc->bmiCategory(32.0))->toBe('obese');
});

it('computes BMR with Mifflin-St Jeor per sex', function (): void {
    // male: 10*80 + 6.25*180 - 5*30 + 5 = 1780
    expect($this->calc->bmr(80, 180, 30, Gender::Male))->toBe(1780.0)
        // female: same base, constant -161 => 1614
        ->and($this->calc->bmr(80, 180, 30, Gender::Female))->toBe(1614.0)
        // other: constant -78 => 1697
        ->and($this->calc->bmr(80, 180, 30, Gender::Other))->toBe(1697.0);
});

it('derives TDEE from BMR and activity', function (): void {
    // 1780 * 1.55 (moderate) = 2759
    expect($this->calc->tdee(1780, ActivityLevel::Moderate))->toBe(2759.0);
});

it('applies the goal adjustment and floors the calorie target', function (): void {
    expect($this->calc->calorieTarget(2759, HealthGoal::Maintain))->toBe(2759)
        ->and($this->calc->calorieTarget(2759, HealthGoal::LoseWeight))->toBe(2259) // -500
        ->and($this->calc->calorieTarget(1000, HealthGoal::LoseWeight))->toBe(1200); // floored
});

it('splits calories into macro grams by goal', function (): void {
    // maintain 30/40/30 at 2000 kcal: P=150g, C=200g, F=67g
    $macros = $this->calc->macroTargets(2000, HealthGoal::Maintain);

    expect($macros->proteinGrams)->toBe(150)
        ->and($macros->carbGrams)->toBe(200)
        ->and($macros->fatGrams)->toBe(67);
});
