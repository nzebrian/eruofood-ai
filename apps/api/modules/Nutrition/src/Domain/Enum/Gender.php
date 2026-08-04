<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Domain\Enum;

/**
 * Biological sex used by the BMR calculation (Mifflin-St Jeor uses a different
 * constant for male and female). `Other` averages the two constants so the
 * estimate remains usable without forcing a binary choice.
 */
enum Gender: string
{
    case Male = 'male';
    case Female = 'female';
    case Other = 'other';
}
