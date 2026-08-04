<?php

declare(strict_types=1);

namespace EruoFood\Ai\Application\Feature;

use EruoFood\Ai\Application\DTO\AiCompletionRequest;
use EruoFood\Ai\Application\DTO\ChatTurn;
use EruoFood\Ai\Application\DTO\GeneratedContent;
use EruoFood\Ai\Application\DTO\GenerationDefaults;
use EruoFood\Ai\Application\Input\CookingTipsInput;
use EruoFood\Ai\Application\Input\SubstitutionInput;
use EruoFood\Ai\Application\Service\AiContextBuilder;
use EruoFood\Ai\Application\Service\AiGateway;
use EruoFood\Ai\Application\Service\FeatureRunner;
use EruoFood\Ai\Application\Service\PromptRegistry;
use EruoFood\Ai\Domain\Conversation\Conversation;
use EruoFood\Ai\Domain\Conversation\ConversationRepository;
use EruoFood\Ai\Domain\Enum\AiFeature;
use EruoFood\Ai\Domain\Enum\MessageRole;
use EruoFood\Ai\Domain\Exception\ConversationNotFound;
use EruoFood\Ai\Domain\ValueObject\PromptVariables;
use EruoFood\Shared\Domain\Clock;

/**
 * The Smart Cooking Assistant plus two adjacent one-shot helpers.
 *
 * {@see chat()} is the stateful, multi-turn assistant: it appends the user's
 * message to a persisted {@see Conversation}, sends the whole thread to the model
 * for context, then records the reply — giving the AI chat history feature. Chat
 * turns are never cached (each turn is unique). {@see cookingTips()} and
 * {@see substitute()} are stateless one-shot generations.
 */
final readonly class CookingAssistant
{
    public function __construct(
        private FeatureRunner $runner,
        private AiContextBuilder $context,
        private PromptRegistry $prompts,
        private AiGateway $gateway,
        private ConversationRepository $conversations,
        private Clock $clock,
        private GenerationDefaults $defaults,
    ) {
    }

    /**
     * Continue (or start) a chat thread and return the assistant's reply.
     *
     * @throws ConversationNotFound when a supplied thread id is unknown or not the user's
     */
    public function chat(string $userId, ?string $conversationId, string $message): ChatTurn
    {
        $conversation = $this->resolveConversation($userId, $conversationId, $message);

        $now = $this->clock->now();
        $conversation->addMessage(MessageRole::User, $message, $now);

        $template = $this->prompts->activeFor(AiFeature::CookingAssistant);
        $persona = $template->render(PromptVariables::fromArray([]))->system;

        $request = new AiCompletionRequest(
            system: $persona,
            messages: $conversation->toAiMessages(),
            maxTokens: $this->defaults->maxTokens,
            temperature: $this->defaults->temperature,
            model: $template->model(),
        );

        $result = $this->gateway->generate(AiFeature::CookingAssistant, $request, $userId, false);

        $conversation->addMessage(MessageRole::Assistant, $result->text, $this->clock->now());
        $this->conversations->save($conversation);

        return new ChatTurn($conversation, $result->text, $result);
    }

    /** Cooking Tips Generation — practical tips for a dish or technique. */
    public function cookingTips(CookingTipsInput $input, ?string $userId): GeneratedContent
    {
        $vars = PromptVariables::fromArray([
            'topic' => $input->topic,
            'skill_level' => $input->skillLevel ?? 'home cook',
        ]);

        return $this->runner->text(AiFeature::CookingTips, $vars, $userId);
    }

    /** Ingredient Substitution — suggest swaps for an ingredient. */
    public function substitute(SubstitutionInput $input, ?string $userId): GeneratedContent
    {
        $vars = PromptVariables::fromArray([
            'ingredient' => $input->ingredient,
            'reason' => $input->reason ?? 'unavailable',
            'dish_context' => $input->dishContext ?? 'a Nigerian dish',
            'dietary' => $this->context->inlineList($input->dietaryPreferences),
        ]);

        return $this->runner->structured(AiFeature::IngredientSubstitution, $vars, $userId);
    }

    private function resolveConversation(string $userId, ?string $conversationId, string $message): Conversation
    {
        if ($conversationId === null) {
            return Conversation::start(
                $this->conversations->nextIdentity(),
                $userId,
                $this->deriveTitle($message),
                $this->clock->now(),
            );
        }

        $conversation = $this->conversations->findById($conversationId);
        if ($conversation === null || $conversation->userId() !== $userId) {
            throw ConversationNotFound::withId($conversationId);
        }

        return $conversation;
    }

    private function deriveTitle(string $message): string
    {
        $trimmed = trim(preg_replace('/\s+/', ' ', $message) ?? $message);

        return mb_strlen($trimmed) > 48 ? mb_substr($trimmed, 0, 47).'…' : ($trimmed === '' ? 'New chat' : $trimmed);
    }
}
