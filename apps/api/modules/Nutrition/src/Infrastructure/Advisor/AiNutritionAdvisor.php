<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Infrastructure\Advisor;

use EruoFood\Ai\Contracts\AiAdviceRequest;
use EruoFood\Ai\Contracts\AiAdvisor;
use EruoFood\Nutrition\Application\DTO\NutritionAdvice;
use EruoFood\Nutrition\Application\Port\NutritionAdvisor;

/**
 * Bridges the Nutrition {@see NutritionAdvisor} port to the AI module's public
 * {@see AiAdvisor} contract. This is the *only* place Nutrition touches the AI
 * context, and it does so through a published Contract — never AI internals —
 * so the modules stay decoupled (Modular Monolith rule).
 */
final readonly class AiNutritionAdvisor implements NutritionAdvisor
{
    public function __construct(private AiAdvisor $ai)
    {
    }

    public function advise(string $system, string $prompt, ?string $userId): NutritionAdvice
    {
        $result = $this->ai->advise(new AiAdviceRequest($system, $prompt, $userId, cacheable: true));

        return new NutritionAdvice($result->text, $result->provider, $result->model, $result->cached);
    }
}
