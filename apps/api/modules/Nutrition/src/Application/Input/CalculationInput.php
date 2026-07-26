<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Application\Input;

use EruoFood\Nutrition\Domain\Enum\ActivityLevel;
use EruoFood\Nutrition\Domain\Enum\Gender;
use EruoFood\Nutrition\Domain\Enum\HealthGoal;

/** Ad-hoc calculator input (no saved profile required). */
final readonly class CalculationInput
{
    public function __construct(
        public float $weightKg,
        public float $heightCm,
        public int $age,
        public Gender $gender,
        public ActivityLevel $activityLevel,
        public HealthGoal $goal,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            weightKg: (float) $data['weight_kg'],
            heightCm: (float) $data['height_cm'],
            age: (int) $data['age'],
            gender: Gender::from((string) $data['gender']),
            activityLevel: ActivityLevel::from((string) ($data['activity_level'] ?? 'moderate')),
            goal: HealthGoal::from((string) ($data['goal'] ?? 'maintain')),
        );
    }
}
