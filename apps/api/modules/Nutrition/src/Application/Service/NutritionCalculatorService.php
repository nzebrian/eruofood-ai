<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Application\Service;

use EruoFood\Nutrition\Application\Input\CalculationInput;
use EruoFood\Nutrition\Domain\Health\HealthProfile;
use EruoFood\Nutrition\Domain\Service\NutritionCalculator;
use EruoFood\Nutrition\Domain\ValueObject\NutritionAssessment;
use EruoFood\Shared\Domain\Clock;

/**
 * Exposes the domain {@see NutritionCalculator} as use cases: assess the saved
 * profile, or run an ad-hoc calculation from supplied values (BMI/BMR/TDEE/
 * calorie & macro targets).
 */
final readonly class NutritionCalculatorService
{
    public function __construct(
        private NutritionCalculator $calculator,
        private HealthProfileService $profileService,
        private Clock $clock,
    ) {
    }

    /** @throws \EruoFood\Nutrition\Domain\Exception\ProfileNotConfigured */
    public function assessForUser(string $userId): NutritionAssessment
    {
        return $this->calculator->assess($this->profileService->getOrFail($userId));
    }

    /** Ad-hoc assessment from supplied values (no saved profile needed). */
    public function assessFromInput(CalculationInput $input): NutritionAssessment
    {
        // Build a transient profile so the same domain path computes everything.
        $profile = HealthProfile::create(
            'anonymous',
            $input->weightKg,
            $input->heightCm,
            $input->age,
            $input->gender,
            $input->activityLevel,
            $input->goal,
            [],
            [],
            [],
            $this->clock->now(),
        );

        return $this->calculator->assess($profile);
    }
}
