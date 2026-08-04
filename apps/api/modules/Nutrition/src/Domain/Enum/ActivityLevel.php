<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Domain\Enum;

/**
 * How active a person is day-to-day. Each level maps to a multiplier applied to
 * BMR to estimate Total Daily Energy Expenditure (TDEE). The concrete factors
 * are configurable (config/nutrition.php); the defaults below are the standard
 * Harris-Benedict / Mifflin values and are used when config is unavailable.
 */
enum ActivityLevel: string
{
    case Sedentary = 'sedentary';
    case Light = 'light';
    case Moderate = 'moderate';
    case Active = 'active';
    case VeryActive = 'very_active';

    public function defaultFactor(): float
    {
        return match ($this) {
            self::Sedentary => 1.2,
            self::Light => 1.375,
            self::Moderate => 1.55,
            self::Active => 1.725,
            self::VeryActive => 1.9,
        };
    }
}
