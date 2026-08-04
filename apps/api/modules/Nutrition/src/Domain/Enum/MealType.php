<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Domain\Enum;

/** Which meal a diary entry or plan slot belongs to. */
enum MealType: string
{
    case Breakfast = 'breakfast';
    case Lunch = 'lunch';
    case Dinner = 'dinner';
    case Snack = 'snack';
}
