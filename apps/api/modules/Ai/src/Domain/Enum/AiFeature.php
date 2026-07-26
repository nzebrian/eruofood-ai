<?php

declare(strict_types=1);

namespace EruoFood\Ai\Domain\Enum;

/**
 * The catalogue of AI capabilities the engine exposes.
 *
 * Each case is a stable key used to (a) select the versioned prompt template
 * for the feature, (b) tag usage/cost ledger rows, and (c) scope rate limits.
 * Adding a feature is a matter of adding a case here and seeding its prompt.
 */
enum AiFeature: string
{
    case RecipeGeneration = 'recipe_generation';
    case RecipeImprovement = 'recipe_improvement';
    case RecipeTranslation = 'recipe_translation';
    case IngredientSubstitution = 'ingredient_substitution';
    case CookingAssistant = 'cooking_assistant';
    case MealSuggestions = 'meal_suggestions';
    case LeftoverRecipes = 'leftover_recipes';
    case RecipeSummarization = 'recipe_summarization';
    case CookingTips = 'cooking_tips';
    case FoodDescription = 'food_description';

    /** Chat features keep multi-turn history; one-shot features do not. */
    public function isConversational(): bool
    {
        return $this === self::CookingAssistant;
    }

    /** Whether responses for this feature are safe to cache (deterministic input → reusable output). */
    public function isCacheable(): bool
    {
        return ! $this->isConversational();
    }
}
