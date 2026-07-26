<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Application\DTO;

use EruoFood\Nutrition\Domain\Diary\DiaryEntry;
use EruoFood\Nutrition\Domain\ValueObject\NutritionAssessment;
use EruoFood\Nutrition\Domain\ValueObject\NutritionFacts;

/**
 * A day's nutrition tracking: the logged entries, their summed totals, and —
 * when the user has a health profile — the day's targets and remaining calories.
 */
final readonly class DailyNutritionSummary
{
    /**
     * @param list<DiaryEntry> $entries
     */
    public function __construct(
        public string $date,
        public array $entries,
        public NutritionFacts $totals,
        public ?NutritionAssessment $targets,
    ) {
    }

    public function remainingCalories(): ?int
    {
        if ($this->targets === null) {
            return null;
        }

        return (int) round($this->targets->calorieTarget - $this->totals->calories);
    }
}
