<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Domain\Enum;

/** The span a meal plan covers. Determines its default number of days. */
enum PlanPeriod: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    public function days(): int
    {
        return match ($this) {
            self::Daily => 1,
            self::Weekly => 7,
            self::Monthly => 30,
        };
    }
}
