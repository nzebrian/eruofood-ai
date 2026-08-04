<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Interface\Http\Controller;

use EruoFood\Nutrition\Application\Input\AnalysisInput;
use EruoFood\Nutrition\Application\Service\MealAnalysisService;
use EruoFood\Nutrition\Interface\Http\Concerns\RespondsWithData;
use EruoFood\Nutrition\Interface\Http\Request\AnalysisRequest;
use Illuminate\Http\JsonResponse;

/** Meal nutrition analysis / recipe nutrition breakdown. */
final readonly class MealAnalysisController
{
    use RespondsWithData;

    public function __construct(private MealAnalysisService $analysis)
    {
    }

    public function analyse(AnalysisRequest $request): JsonResponse
    {
        $result = $this->analysis->analyse(AnalysisInput::fromArray($request->validated()));

        return $this->data($result->toArray());
    }
}
