<?php

declare(strict_types=1);

namespace EruoFood\Ai\Application\Feature;

use EruoFood\Ai\Application\DTO\GeneratedContent;
use EruoFood\Ai\Application\Input\FoodDescriptionInput;
use EruoFood\Ai\Application\Input\SummarizationInput;
use EruoFood\Ai\Application\Input\TranslationInput;
use EruoFood\Ai\Application\Service\AiContextBuilder;
use EruoFood\Ai\Application\Service\FeatureRunner;
use EruoFood\Ai\Domain\Enum\AiFeature;
use EruoFood\Ai\Domain\ValueObject\PromptVariables;

/**
 * Text-generation features: translate a recipe, summarise a recipe, and write a
 * mouth-watering food description. All return prose, so they run through the
 * text path of the {@see FeatureRunner}.
 */
final readonly class ContentWriter
{
    public function __construct(
        private FeatureRunner $runner,
        private AiContextBuilder $context,
    ) {
    }

    /** Recipe Translation — translate recipe content into a target language. */
    public function translate(TranslationInput $input, ?string $userId): GeneratedContent
    {
        $vars = PromptVariables::fromArray([
            'target_language' => $input->targetLanguage,
            'content' => $input->content,
        ]);

        return $this->runner->text(AiFeature::RecipeTranslation, $vars, $userId);
    }

    /** Recipe Summarization — condense a recipe into a short overview. */
    public function summarize(SummarizationInput $input, ?string $userId): GeneratedContent
    {
        $vars = PromptVariables::fromArray([
            'max_words' => $input->maxWords ?? '80',
            'content' => $input->content,
        ]);

        return $this->runner->text(AiFeature::RecipeSummarization, $vars, $userId);
    }

    /** Food Description Generation — SEO-friendly description of a dish. */
    public function describeFood(FoodDescriptionInput $input, ?string $userId): GeneratedContent
    {
        $vars = PromptVariables::fromArray([
            'food_name' => $input->foodName,
            'region' => $input->region ?? 'Nigeria',
            'keywords' => $this->context->inlineList($input->keywords, 'none'),
        ]);

        return $this->runner->text(AiFeature::FoodDescription, $vars, $userId);
    }
}
