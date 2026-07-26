<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Domain\Health;

use DateTimeImmutable;
use EruoFood\Nutrition\Domain\Enum\ActivityLevel;
use EruoFood\Nutrition\Domain\Enum\Gender;
use EruoFood\Nutrition\Domain\Enum\HealthGoal;
use EruoFood\Shared\Domain\Exception\InvalidArgumentException;

/**
 * A user's health profile — the personal inputs the nutrition calculators and
 * personalisation depend on. One profile per user (its identity IS the user id).
 *
 * The aggregate guards the physical invariants (positive, human-plausible weight
 * / height / age) so every downstream calculation starts from valid data.
 */
final class HealthProfile
{
    /**
     * @param list<string> $dietaryPreferences
     * @param list<string> $allergies
     * @param list<string> $medicalRestrictions
     */
    private function __construct(
        private readonly string $userId,
        private float $weightKg,
        private float $heightCm,
        private int $age,
        private Gender $gender,
        private ActivityLevel $activityLevel,
        private HealthGoal $goal,
        private array $dietaryPreferences,
        private array $allergies,
        private array $medicalRestrictions,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * @param list<string> $dietaryPreferences
     * @param list<string> $allergies
     * @param list<string> $medicalRestrictions
     */
    public static function create(
        string $userId,
        float $weightKg,
        float $heightCm,
        int $age,
        Gender $gender,
        ActivityLevel $activityLevel,
        HealthGoal $goal,
        array $dietaryPreferences,
        array $allergies,
        array $medicalRestrictions,
        DateTimeImmutable $now,
    ): self {
        self::guard($weightKg, $heightCm, $age);

        return new self(
            $userId,
            $weightKg,
            $heightCm,
            $age,
            $gender,
            $activityLevel,
            $goal,
            array_values($dietaryPreferences),
            array_values($allergies),
            array_values($medicalRestrictions),
            $now,
            $now,
        );
    }

    /**
     * @param list<string> $dietaryPreferences
     * @param list<string> $allergies
     * @param list<string> $medicalRestrictions
     */
    public static function reconstitute(
        string $userId,
        float $weightKg,
        float $heightCm,
        int $age,
        Gender $gender,
        ActivityLevel $activityLevel,
        HealthGoal $goal,
        array $dietaryPreferences,
        array $allergies,
        array $medicalRestrictions,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ): self {
        return new self(
            $userId,
            $weightKg,
            $heightCm,
            $age,
            $gender,
            $activityLevel,
            $goal,
            array_values($dietaryPreferences),
            array_values($allergies),
            array_values($medicalRestrictions),
            $createdAt,
            $updatedAt,
        );
    }

    /**
     * @param list<string> $dietaryPreferences
     * @param list<string> $allergies
     * @param list<string> $medicalRestrictions
     */
    public function update(
        float $weightKg,
        float $heightCm,
        int $age,
        Gender $gender,
        ActivityLevel $activityLevel,
        HealthGoal $goal,
        array $dietaryPreferences,
        array $allergies,
        array $medicalRestrictions,
        DateTimeImmutable $now,
    ): void {
        self::guard($weightKg, $heightCm, $age);
        $this->weightKg = $weightKg;
        $this->heightCm = $heightCm;
        $this->age = $age;
        $this->gender = $gender;
        $this->activityLevel = $activityLevel;
        $this->goal = $goal;
        $this->dietaryPreferences = array_values($dietaryPreferences);
        $this->allergies = array_values($allergies);
        $this->medicalRestrictions = array_values($medicalRestrictions);
        $this->updatedAt = $now;
    }

    private static function guard(float $weightKg, float $heightCm, int $age): void
    {
        if ($weightKg < 20 || $weightKg > 500) {
            throw new InvalidArgumentException('Weight must be between 20 and 500 kg.');
        }
        if ($heightCm < 50 || $heightCm > 260) {
            throw new InvalidArgumentException('Height must be between 50 and 260 cm.');
        }
        if ($age < 1 || $age > 120) {
            throw new InvalidArgumentException('Age must be between 1 and 120.');
        }
    }

    public function userId(): string
    {
        return $this->userId;
    }

    public function weightKg(): float
    {
        return $this->weightKg;
    }

    public function heightCm(): float
    {
        return $this->heightCm;
    }

    public function age(): int
    {
        return $this->age;
    }

    public function gender(): Gender
    {
        return $this->gender;
    }

    public function activityLevel(): ActivityLevel
    {
        return $this->activityLevel;
    }

    public function goal(): HealthGoal
    {
        return $this->goal;
    }

    /** @return list<string> */
    public function dietaryPreferences(): array
    {
        return $this->dietaryPreferences;
    }

    /** @return list<string> */
    public function allergies(): array
    {
        return $this->allergies;
    }

    /** @return list<string> */
    public function medicalRestrictions(): array
    {
        return $this->medicalRestrictions;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
