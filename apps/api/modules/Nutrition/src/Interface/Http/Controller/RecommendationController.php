<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Interface\Http\Controller;

use EruoFood\Nutrition\Application\Service\RecommendationService;
use EruoFood\Nutrition\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Nutrition\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** AI personalisation: meal recommendations, suggestions, diet advice, insights. */
final readonly class RecommendationController
{
    use RespondsWithData;
    use ResolvesAuthUser;

    public function __construct(private RecommendationService $recommendations)
    {
    }

    public function meals(Request $request): JsonResponse
    {
        return $this->data($this->recommendations->personalisedMeals($this->currentUserId($request))->toArray());
    }

    public function suggestions(Request $request): JsonResponse
    {
        $date = ((string) $request->string('date')) ?: date('Y-m-d');

        return $this->data(
            $this->recommendations->nutritionSuggestions($this->currentUserId($request), $date)->toArray(),
        );
    }

    public function dietImprovement(Request $request): JsonResponse
    {
        return $this->data($this->recommendations->dietImprovement($this->currentUserId($request))->toArray());
    }

    public function weeklyInsights(Request $request): JsonResponse
    {
        return $this->data($this->recommendations->weeklyInsights($this->currentUserId($request))->toArray());
    }
}
