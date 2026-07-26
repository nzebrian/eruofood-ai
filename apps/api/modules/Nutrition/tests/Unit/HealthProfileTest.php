<?php

declare(strict_types=1);

use EruoFood\Nutrition\Domain\Enum\ActivityLevel;
use EruoFood\Nutrition\Domain\Enum\Gender;
use EruoFood\Nutrition\Domain\Enum\HealthGoal;
use EruoFood\Nutrition\Domain\Health\HealthProfile;
use EruoFood\Shared\Domain\Exception\InvalidArgumentException;

function makeProfile(float $weight = 80, float $height = 180, int $age = 30): HealthProfile
{
    return HealthProfile::create(
        'u1',
        $weight,
        $height,
        $age,
        Gender::Male,
        ActivityLevel::Moderate,
        HealthGoal::Maintain,
        ['halal'],
        ['peanut'],
        [],
        new DateTimeImmutable('2026-07-26T00:00:00Z'),
    );
}

it('stores the profile fields', function (): void {
    $p = makeProfile();

    expect($p->userId())->toBe('u1')
        ->and($p->weightKg())->toBe(80.0)
        ->and($p->goal())->toBe(HealthGoal::Maintain)
        ->and($p->dietaryPreferences())->toBe(['halal'])
        ->and($p->allergies())->toBe(['peanut']);
});

it('rejects an implausible weight', function (): void {
    makeProfile(weight: 5);
})->throws(InvalidArgumentException::class);

it('rejects an implausible height', function (): void {
    makeProfile(height: 10);
})->throws(InvalidArgumentException::class);

it('updates fields and bumps the timestamp', function (): void {
    $p = makeProfile();
    $p->update(
        90,
        182,
        31,
        Gender::Male,
        ActivityLevel::Active,
        HealthGoal::GainMuscle,
        [],
        [],
        ['low_sodium'],
        new DateTimeImmutable('2026-07-27T00:00:00Z'),
    );

    expect($p->weightKg())->toBe(90.0)
        ->and($p->goal())->toBe(HealthGoal::GainMuscle)
        ->and($p->medicalRestrictions())->toBe(['low_sodium']);
});
