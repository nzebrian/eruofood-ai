<?php

declare(strict_types=1);

namespace EruoFood\Ai\Application\Service;

use EruoFood\Ai\Application\DTO\AiCompletionResult;
use EruoFood\Ai\Application\DTO\ChatTurn;
use EruoFood\Ai\Application\DTO\GeneratedContent;
use EruoFood\Ai\Domain\Conversation\Conversation;
use EruoFood\Ai\Domain\Conversation\ConversationMessage;
use EruoFood\Ai\Domain\Prompt\PromptTemplate;

/**
 * Maps AI application results to API-shaped arrays, keeping controllers thin and
 * the response envelope consistent. Every generation carries a `meta` block so
 * clients can see which provider/model answered, the token spend, and whether
 * the answer was served from cache.
 */
final readonly class AiPresenter
{
    /** @return array<string, mixed> */
    public function generated(GeneratedContent $content): array
    {
        return [
            'content' => $content->text ?? $content->data,
            'meta' => $this->meta($content->meta),
        ];
    }

    /** @return array<string, mixed> */
    public function chatTurn(ChatTurn $turn): array
    {
        return [
            'conversation_id' => $turn->conversation->id(),
            'reply' => $turn->reply,
            'conversation' => $this->conversation($turn->conversation),
            'meta' => $this->meta($turn->meta),
        ];
    }

    /** @return array<string, mixed> */
    public function conversationSummary(Conversation $c): array
    {
        return [
            'id' => $c->id(),
            'title' => $c->title(),
            'feature' => $c->feature()->value,
            'message_count' => $c->messageCount(),
            'created_at' => $c->createdAt()->format(DATE_ATOM),
            'updated_at' => $c->updatedAt()->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function conversation(Conversation $c): array
    {
        return array_merge($this->conversationSummary($c), [
            'messages' => array_map(
                static fn (ConversationMessage $m): array => $m->toArray(),
                $c->messages(),
            ),
        ]);
    }

    /** @return array<string, mixed> */
    public function prompt(PromptTemplate $t): array
    {
        return [
            'id' => $t->id(),
            'feature' => $t->feature()->value,
            'version' => $t->version(),
            'name' => $t->name(),
            'system_template' => $t->systemTemplate(),
            'user_template' => $t->userTemplate(),
            'model' => $t->model(),
            'variables' => $t->variables(),
            'active' => $t->isActive(),
            'created_at' => $t->createdAt()->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function meta(AiCompletionResult $result): array
    {
        return [
            'provider' => $result->provider->value,
            'model' => $result->model,
            'cached' => $result->cached,
            'tokens' => $result->tokens->toArray(),
            'finish_reason' => $result->finishReason,
        ];
    }
}
