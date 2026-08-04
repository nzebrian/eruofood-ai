<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Application\Input;

use EruoFood\Nutrition\Domain\Enum\PlanPeriod;
use EruoFood\Nutrition\Domain\Plan\MealPlanEntry;

/** Validated input for creating a meal plan. */
final readonly class MealPlanInput
{
    /**
     * @param list<MealPlanEntry> $entries
     */
    public function __construct(
        public string $title,
        public PlanPeriod $period,
        public string $startDate,
        public array $entries,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $entries = array_map(
            static fn (array $e): MealPlanEntry => MealPlanEntry::fromArray($e),
            $data['entries'] ?? [],
        );

        return new self(
            title: (string) $data['title'],
            period: PlanPeriod::from((string) ($data['period'] ?? 'weekly')),
            startDate: (string) $data['start_date'],
            entries: array_values($entries),
        );
    }
}
