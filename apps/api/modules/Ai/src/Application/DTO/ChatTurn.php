<?php

declare(strict_types=1);

namespace EruoFood\Ai\Application\DTO;

use EruoFood\Ai\Domain\Conversation\Conversation;

/**
 * The result of one Smart Cooking Assistant exchange: the assistant's reply, the
 * completion metadata, and the updated conversation (so callers can surface the
 * new message and the thread id).
 */
final readonly class ChatTurn
{
    public function __construct(
        public Conversation $conversation,
        public string $reply,
        public AiCompletionResult $meta,
    ) {
    }
}
