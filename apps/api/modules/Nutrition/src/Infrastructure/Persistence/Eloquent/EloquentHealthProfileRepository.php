<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Nutrition\Domain\Enum\ActivityLevel;
use EruoFood\Nutrition\Domain\Enum\Gender;
use EruoFood\Nutrition\Domain\Enum\HealthGoal;
use EruoFood\Nutrition\Domain\Health\HealthProfile;
use EruoFood\Nutrition\Domain\Health\HealthProfileRepository;
use EruoFood\Nutrition\Infrastructure\Persistence\Eloquent\Model\HealthProfileModel;

final class EloquentHealthProfileRepository implements HealthProfileRepository
{
    public function findByUser(string $userId): ?HealthProfile
    {
        $model = HealthProfileModel::query()->find($userId);

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function save(HealthProfile $profile): void
    {
        $model = HealthProfileModel::query()->find($profile->userId()) ?? new HealthProfileModel();
        $model->user_id = $profile->userId();
        $model->weight_kg = $profile->weightKg();
        $model->height_cm = $profile->heightCm();
        $model->age = $profile->age();
        $model->gender = $profile->gender()->value;
        $model->activity_level = $profile->activityLevel()->value;
        $model->goal = $profile->goal()->value;
        $model->dietary_preferences = $profile->dietaryPreferences();
        $model->allergies = $profile->allergies();
        $model->medical_restrictions = $profile->medicalRestrictions();
        $model->created_at = $profile->createdAt();
        $model->updated_at = $profile->updatedAt();
        $model->save();
    }

    private function toDomain(HealthProfileModel $m): HealthProfile
    {
        return HealthProfile::reconstitute(
            userId: $m->user_id,
            weightKg: $m->weight_kg,
            heightCm: $m->height_cm,
            age: $m->age,
            gender: Gender::from($m->gender),
            activityLevel: ActivityLevel::from($m->activity_level),
            goal: HealthGoal::from($m->goal),
            dietaryPreferences: $m->dietary_preferences ?? [],
            allergies: $m->allergies ?? [],
            medicalRestrictions: $m->medical_restrictions ?? [],
            createdAt: DateTimeImmutable::createFromInterface($m->created_at),
            updatedAt: DateTimeImmutable::createFromInterface($m->updated_at),
        );
    }
}
