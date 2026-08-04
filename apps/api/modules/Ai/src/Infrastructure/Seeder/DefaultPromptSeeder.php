<?php

declare(strict_types=1);

namespace EruoFood\Ai\Infrastructure\Seeder;

use EruoFood\Ai\Domain\Enum\AiFeature;
use EruoFood\Ai\Domain\Prompt\PromptRepository;
use EruoFood\Ai\Domain\Prompt\PromptTemplate;
use EruoFood\Shared\Domain\Clock;
use Illuminate\Database\Seeder;

/**
 * Seeds version 1 of the active prompt for every AI feature.
 *
 * The prompts are the production defaults of the Prompt Management System.
 * Structured features instruct the model to answer with a single JSON object so
 * the {@see \EruoFood\Ai\Application\Service\AiResponseParser} can decode them;
 * text features ask for prose. Seeding is idempotent — a feature that already
 * has an active template is left untouched, so re-running never clobbers edits.
 */
final class DefaultPromptSeeder extends Seeder
{
    public function __construct(
        private readonly PromptRepository $prompts,
        private readonly Clock $clock,
    ) {
    }

    public function run(): void
    {
        foreach ($this->definitions() as $definition) {
            /** @var array{feature: AiFeature, name: string, system: string, user: string, variables: list<string>} $definition */
            if ($this->prompts->activeForFeature($definition['feature']) !== null) {
                continue;
            }

            $this->prompts->save(PromptTemplate::create(
                id: $this->prompts->nextIdentity(),
                feature: $definition['feature'],
                version: 1,
                name: $definition['name'],
                systemTemplate: $definition['system'],
                userTemplate: $definition['user'],
                model: null, // each provider uses its configured default model
                variables: $definition['variables'],
                active: true,
                createdAt: $this->clock->now(),
            ));
        }
    }

    /**
     * @return list<array{feature: AiFeature, name: string, system: string, user: string, variables: list<string>}>
     */
    private function definitions(): array
    {
        $chef = 'You are a master Nigerian chef and recipe developer for the EruoFood AI platform. '
            .'You create authentic, practical Nigerian recipes with real ingredients and clear steps.';

        return [
            [
                'feature' => AiFeature::RecipeGeneration,
                'name' => 'Recipe generation (default)',
                'system' => $chef,
                'user' => "Create a recipe for {{ dish_name }} serving {{ servings }} people.\n"
                    ."Difficulty: {{ difficulty }}. Dietary requirements: {{ dietary }}.\n"
                    ."Ingredients the cook already has:\n{{ available_ingredients }}\n"
                    ."Additional notes: {{ notes }}\n\n"
                    .'Respond ONLY with a single valid JSON object with keys: '
                    .'title, summary, servings, difficulty, ingredients (array of strings), '
                    .'steps (array of strings), tips (array of strings).',
                'variables' => ['dish_name', 'servings', 'difficulty', 'dietary', 'available_ingredients', 'notes'],
            ],
            [
                'feature' => AiFeature::RecipeImprovement,
                'name' => 'Recipe improvement (default)',
                'system' => 'You are a Nigerian culinary expert who refines recipes while keeping them authentic.',
                'user' => "Improve the following recipe so as to {{ goal }}. Keep it authentically Nigerian.\n\n"
                    ."{{ recipe }}\n\n"
                    .'Respond ONLY with a single valid JSON object with keys: title, summary, '
                    .'ingredients (array), steps (array), changes (array of strings explaining each change).',
                'variables' => ['goal', 'recipe'],
            ],
            [
                'feature' => AiFeature::LeftoverRecipes,
                'name' => 'Leftover recipe generator (default)',
                'system' => $chef,
                'user' => "I have these leftover ingredients:\n{{ ingredients }}\n"
                    ."Dietary requirements: {{ dietary }}. Desired meal: {{ meal_type }}.\n"
                    ."Suggest one Nigerian-inspired recipe using mostly these ingredients.\n\n"
                    .'Respond ONLY with a single valid JSON object with keys: '
                    .'title, summary, ingredients (array), steps (array).',
                'variables' => ['ingredients', 'dietary', 'meal_type'],
            ],
            [
                'feature' => AiFeature::MealSuggestions,
                'name' => 'Meal suggestions (default)',
                'system' => 'You are a Nigerian meal planner who suggests balanced, affordable everyday meals.',
                'user' => "Suggest {{ count }} Nigerian {{ meal_type }} ideas.\n"
                    ."Dietary requirements: {{ dietary }}. Budget: {{ budget }}.\n\n"
                    .'Respond ONLY with a single valid JSON object with key "suggestions" as an array; '
                    .'each item has: name, description, key_ingredients (array of strings).',
                'variables' => ['count', 'meal_type', 'dietary', 'budget'],
            ],
            [
                'feature' => AiFeature::IngredientSubstitution,
                'name' => 'Ingredient substitution (default)',
                'system' => 'You are a Nigerian cooking expert who knows local and international ingredient swaps.',
                'user' => "Suggest substitutes for {{ ingredient }} (reason: {{ reason }}) "
                    ."in the context of {{ dish_context }}.\nDietary requirements: {{ dietary }}.\n\n"
                    .'Respond ONLY with a single valid JSON object with key "substitutions" as an array; '
                    .'each item has: substitute, ratio, notes.',
                'variables' => ['ingredient', 'reason', 'dish_context', 'dietary'],
            ],
            [
                'feature' => AiFeature::RecipeTranslation,
                'name' => 'Recipe translation (default)',
                'system' => 'You are a professional translator fluent in English and major Nigerian languages. '
                    .'You preserve cooking terms, quantities and measurements exactly.',
                'user' => "Translate the following recipe content into {{ target_language }}:\n\n{{ content }}",
                'variables' => ['target_language', 'content'],
            ],
            [
                'feature' => AiFeature::RecipeSummarization,
                'name' => 'Recipe summarization (default)',
                'system' => 'You write concise, appetising recipe summaries.',
                'user' => "Summarise the following recipe in at most {{ max_words }} words, "
                    ."capturing the dish, key ingredients and method:\n\n{{ content }}",
                'variables' => ['max_words', 'content'],
            ],
            [
                'feature' => AiFeature::CookingTips,
                'name' => 'Cooking tips (default)',
                'system' => 'You are a friendly Nigerian cooking coach who gives practical, safe kitchen advice.',
                'user' => 'Give practical cooking tips about {{ topic }} for a {{ skill_level }}. '
                    .'Present them as a short numbered list.',
                'variables' => ['topic', 'skill_level'],
            ],
            [
                'feature' => AiFeature::FoodDescription,
                'name' => 'Food description (default)',
                'system' => 'You are a food writer who crafts appetising, SEO-friendly dish descriptions.',
                'user' => 'Write an appetising 2-3 sentence description of {{ food_name }} from {{ region }}. '
                    .'Naturally include these keywords where relevant: {{ keywords }}.',
                'variables' => ['food_name', 'region', 'keywords'],
            ],
            [
                'feature' => AiFeature::CookingAssistant,
                'name' => 'Smart cooking assistant (default)',
                'system' => "You are EruoFood AI's Smart Cooking Assistant — a friendly, knowledgeable Nigerian "
                    .'cooking companion. Help users with recipes, techniques, ingredient questions and meal ideas. '
                    .'Be concise, practical and encouraging. When a request is ambiguous, ask a clarifying question.',
                'user' => '{{ message }}',
                'variables' => ['message'],
            ],
        ];
    }
}
