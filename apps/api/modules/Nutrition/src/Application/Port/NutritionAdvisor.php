<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Application\Port;

use EruoFood\Nutrition\Application\DTO\NutritionAdvice;

/**
 * Port the personalisation features depend on to obtain AI-generated advice.
 *
 * The Nutrition application layer talks only to this interface; the
 * infrastructure adapter bridges it to the AI module's published
 * {@see \EruoFood\Ai\Contracts\AiAdvisor} contract (Dependency Inversion +
 * cross-context integration through Contracts only).
 */
interface NutritionAdvisor
{
    public function advise(string $system, string $prompt, ?string $userId): NutritionAdvice;
}
