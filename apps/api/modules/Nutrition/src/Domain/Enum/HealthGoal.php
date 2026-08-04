<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Domain\Enum;

/**
 * The user's dietary objective. Drives both the calorie adjustment applied to
 * TDEE and the macronutrient split (see config/nutrition.php).
 */
enum HealthGoal: string
{
    case LoseWeight = 'lose_weight';
    case Maintain = 'maintain';
    case GainWeight = 'gain_weight';
    case GainMuscle = 'gain_muscle';

    public function defaultCalorieAdjustment(): int
    {
        return match ($this) {
            self::LoseWeight => -500,
            self::Maintain => 0,
            self::GainWeight => 500,
            self::GainMuscle => 250,
        };
    }
}
