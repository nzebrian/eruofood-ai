<?php

declare(strict_types=1);

namespace EruoFood\Ai\Interface\Http\Controller;

use EruoFood\Ai\Application\Feature\CookingAssistant;
use EruoFood\Ai\Application\Feature\MealPlanner;
use EruoFood\Ai\Application\Input\CookingTipsInput;
use EruoFood\Ai\Application\Input\MealSuggestionInput;
use EruoFood\Ai\Application\Input\SubstitutionInput;
use EruoFood\Ai\Application\Service\AiPresenter;
use EruoFood\Ai\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Ai\Interface\Http\Concerns\RespondsWithData;
use EruoFood\Ai\Interface\Http\Request\ChatRequest;
use EruoFood\Ai\Interface\Http\Request\CookingTipsRequest;
use EruoFood\Ai\Interface\Http\Request\MealSuggestionRequest;
use EruoFood\Ai\Interface\Http\Request\SubstitutionRequest;
use Illuminate\Http\JsonResponse;

/** Smart Cooking Assistant, tips, substitutions & meal suggestions (authenticated). */
final readonly class AiAssistantController
{
    use RespondsWithData;
    use ResolvesAuthUser;

    public function __construct(
        private CookingAssistant $assistant,
        private MealPlanner $meals,
        private AiPresenter $presenter,
    ) {
    }

    public function chat(ChatRequest $request): JsonResponse
    {
        $data = $request->validated();
        $turn = $this->assistant->chat(
            $this->currentUserId($request),
            isset($data['conversation_id']) ? (string) $data['conversation_id'] : null,
            (string) $data['message'],
        );

        return $this->data($this->presenter->chatTurn($turn));
    }

    public function tips(CookingTipsRequest $request): JsonResponse
    {
        $content = $this->assistant->cookingTips(
            CookingTipsInput::fromArray($request->validated()),
            $this->currentUserId($request),
        );

        return $this->data($this->presenter->generated($content));
    }

    public function substitute(SubstitutionRequest $request): JsonResponse
    {
        $content = $this->assistant->substitute(
            SubstitutionInput::fromArray($request->validated()),
            $this->currentUserId($request),
        );

        return $this->data($this->presenter->generated($content));
    }

    public function mealSuggestions(MealSuggestionRequest $request): JsonResponse
    {
        $content = $this->meals->suggest(
            MealSuggestionInput::fromArray($request->validated()),
            $this->currentUserId($request),
        );

        return $this->data($this->presenter->generated($content));
    }
}
