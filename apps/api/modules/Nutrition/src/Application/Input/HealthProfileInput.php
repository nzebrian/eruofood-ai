<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Application\Input;

use EruoFood\Nutrition\Domain\Enum\ActivityLevel;
use EruoFood\Nutrition\Domain\Enum\Gender;
use EruoFood\Nutrition\Domain\Enum\HealthGoal;

/** Validated input for creating/updating a health profile. */
final readonly class HealthProfileInput
{
    /**
     * @param list<string> $dietaryPreferences
     * @param list<string> $allergies
     * @param list<string> $medicalRestrictions
     */
    public function __construct(
        public float $weightKg,
        public float $heightCm,
        public int $age,
        public Gender $gender,
        public ActivityLevel $activityLevel,
        public HealthGoal $goal,
        public array $dietaryPreferences,
        public array $allergies,
        public array $medicalRestrictions,
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
            activityLevel: ActivityLevel::from((string) $data['activity_level']),
            goal: HealthGoal::from((string) $data['goal']),
            dietaryPreferences: array_values(array_map('strval', $data['dietary_preferences'] ?? [])),
            allergies: array_values(array_map('strval', $data['allergies'] ?? [])),
            medicalRestrictions: array_values(array_map('strval', $data['medical_restrictions'] ?? [])),
        );
    }
}
