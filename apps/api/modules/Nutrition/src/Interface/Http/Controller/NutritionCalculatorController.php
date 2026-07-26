<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Interface\Http\Controller;

use EruoFood\Nutrition\Application\Input\CalculationInput;
use EruoFood\Nutrition\Application\Service\NutritionCalculatorService;
use EruoFood\Nutrition\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Nutrition\Interface\Http\Concerns\RespondsWithData;
use EruoFood\Nutrition\Interface\Http\Request\CalculationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The nutrition calculators: BMI, BMR, TDEE, daily calorie target and macro
 * split — either for the caller's saved profile or ad-hoc from posted values.
 */
final readonly class NutritionCalculatorController
{
    use RespondsWithData;
    use ResolvesAuthUser;

    public function __construct(private NutritionCalculatorService $calculator)
    {
    }

    /** Assess the authenticated user's saved profile. */
    public function assess(Request $request): JsonResponse
    {
        return $this->data($this->calculator->assessForUser($this->currentUserId($request))->toArray());
    }

    /** Ad-hoc calculation from supplied values (no saved profile needed). */
    public function calculate(CalculationRequest $request): JsonResponse
    {
        $assessment = $this->calculator->assessFromInput(CalculationInput::fromArray($request->validated()));

        return $this->data($assessment->toArray());
    }
}
