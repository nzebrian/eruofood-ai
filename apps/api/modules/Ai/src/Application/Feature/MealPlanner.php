<?php

declare(strict_types=1);

namespace EruoFood\Ai\Application\Feature;

use EruoFood\Ai\Application\DTO\GeneratedContent;
use EruoFood\Ai\Application\Input\MealSuggestionInput;
use EruoFood\Ai\Application\Service\AiContextBuilder;
use EruoFood\Ai\Application\Service\FeatureRunner;
use EruoFood\Ai\Domain\Enum\AiFeature;
use EruoFood\Ai\Domain\ValueObject\PromptVariables;

/** Meal Suggestions — propose a set of dishes for a meal, diet and budget. */
final readonly class MealPlanner
{
    public function __construct(
        private FeatureRunner $runner,
        private AiContextBuilder $context,
    ) {
    }

    public function suggest(MealSuggestionInput $input, ?string $userId): GeneratedContent
    {
        $vars = PromptVariables::fromArray([
            'meal_type' => $input->mealType,
            'count' => $input->count,
            'dietary' => $this->context->inlineList($input->dietaryPreferences),
            'budget' => $input->budget ?? 'any budget',
        ]);

        return $this->runner->structured(AiFeature::MealSuggestions, $vars, $userId);
    }
}
