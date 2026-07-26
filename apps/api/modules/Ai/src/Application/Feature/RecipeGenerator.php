<?php

declare(strict_types=1);

namespace EruoFood\Ai\Application\Feature;

use EruoFood\Ai\Application\DTO\GeneratedContent;
use EruoFood\Ai\Application\Input\LeftoverInput;
use EruoFood\Ai\Application\Input\RecipeGenerationInput;
use EruoFood\Ai\Application\Input\RecipeImprovementInput;
use EruoFood\Ai\Application\Service\AiContextBuilder;
use EruoFood\Ai\Application\Service\FeatureRunner;
use EruoFood\Ai\Domain\Enum\AiFeature;
use EruoFood\Ai\Domain\ValueObject\PromptVariables;

/**
 * Recipe-authoring AI features: generate a brand-new recipe, improve an existing
 * one, and turn leftovers into a recipe. Each produces a structured recipe the
 * Catalog can later persist, so all three request JSON output.
 */
final readonly class RecipeGenerator
{
    public function __construct(
        private FeatureRunner $runner,
        private AiContextBuilder $context,
    ) {
    }

    /** AI Recipe Generation — create a Nigerian recipe from a brief. */
    public function generate(RecipeGenerationInput $input, ?string $userId): GeneratedContent
    {
        $vars = PromptVariables::fromArray([
            'dish_name' => $input->dishName,
            'servings' => $input->servings,
            'difficulty' => $input->difficulty ?? 'any',
            'dietary' => $this->context->inlineList($input->dietaryPreferences),
            'available_ingredients' => $this->context->bulletList(
                $input->availableIngredients,
                'no specific ingredients provided — use authentic staples',
            ),
            'notes' => $input->notes ?? 'none',
        ]);

        return $this->runner->structured(AiFeature::RecipeGeneration, $vars, $userId);
    }

    /** Recipe Improvement — refine an existing recipe toward a stated goal. */
    public function improve(RecipeImprovementInput $input, ?string $userId): GeneratedContent
    {
        $vars = PromptVariables::fromArray([
            'goal' => $input->goal,
            'recipe' => $this->context->recipeBlock($input->title, $input->ingredients, $input->steps),
        ]);

        return $this->runner->structured(AiFeature::RecipeImprovement, $vars, $userId);
    }

    /** Leftover Recipe Generator — build a dish from what the user already has. */
    public function fromLeftovers(LeftoverInput $input, ?string $userId): GeneratedContent
    {
        $vars = PromptVariables::fromArray([
            'ingredients' => $this->context->bulletList($input->ingredients),
            'dietary' => $this->context->inlineList($input->dietaryPreferences),
            'meal_type' => $input->mealType ?? 'any meal',
        ]);

        return $this->runner->structured(AiFeature::LeftoverRecipes, $vars, $userId);
    }
}
