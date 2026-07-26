<?php

declare(strict_types=1);

namespace EruoFood\Ai\Interface\Http\Controller;

use EruoFood\Ai\Application\Feature\ContentWriter;
use EruoFood\Ai\Application\Feature\RecipeGenerator;
use EruoFood\Ai\Application\Input\FoodDescriptionInput;
use EruoFood\Ai\Application\Input\LeftoverInput;
use EruoFood\Ai\Application\Input\RecipeGenerationInput;
use EruoFood\Ai\Application\Input\RecipeImprovementInput;
use EruoFood\Ai\Application\Input\SummarizationInput;
use EruoFood\Ai\Application\Input\TranslationInput;
use EruoFood\Ai\Application\Service\AiPresenter;
use EruoFood\Ai\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Ai\Interface\Http\Concerns\RespondsWithData;
use EruoFood\Ai\Interface\Http\Request\FoodDescriptionRequest;
use EruoFood\Ai\Interface\Http\Request\LeftoverRequest;
use EruoFood\Ai\Interface\Http\Request\RecipeGenerationRequest;
use EruoFood\Ai\Interface\Http\Request\RecipeImprovementRequest;
use EruoFood\Ai\Interface\Http\Request\SummarizationRequest;
use EruoFood\Ai\Interface\Http\Request\TranslationRequest;
use Illuminate\Http\JsonResponse;

/** AI recipe-authoring & content endpoints (authenticated). */
final readonly class AiRecipeController
{
    use RespondsWithData;
    use ResolvesAuthUser;

    public function __construct(
        private RecipeGenerator $recipes,
        private ContentWriter $content,
        private AiPresenter $presenter,
    ) {
    }

    public function generate(RecipeGenerationRequest $request): JsonResponse
    {
        $content = $this->recipes->generate(
            RecipeGenerationInput::fromArray($request->validated()),
            $this->currentUserId($request),
        );

        return $this->data($this->presenter->generated($content));
    }

    public function improve(RecipeImprovementRequest $request): JsonResponse
    {
        $content = $this->recipes->improve(
            RecipeImprovementInput::fromArray($request->validated()),
            $this->currentUserId($request),
        );

        return $this->data($this->presenter->generated($content));
    }

    public function leftovers(LeftoverRequest $request): JsonResponse
    {
        $content = $this->recipes->fromLeftovers(
            LeftoverInput::fromArray($request->validated()),
            $this->currentUserId($request),
        );

        return $this->data($this->presenter->generated($content));
    }

    public function summarize(SummarizationRequest $request): JsonResponse
    {
        $content = $this->content->summarize(
            SummarizationInput::fromArray($request->validated()),
            $this->currentUserId($request),
        );

        return $this->data($this->presenter->generated($content));
    }

    public function translate(TranslationRequest $request): JsonResponse
    {
        $content = $this->content->translate(
            TranslationInput::fromArray($request->validated()),
            $this->currentUserId($request),
        );

        return $this->data($this->presenter->generated($content));
    }

    public function describeFood(FoodDescriptionRequest $request): JsonResponse
    {
        $content = $this->content->describeFood(
            FoodDescriptionInput::fromArray($request->validated()),
            $this->currentUserId($request),
        );

        return $this->data($this->presenter->generated($content));
    }
}
