<?php

declare(strict_types=1);

namespace EruoFood\Ai\Domain\Conversation;

use DateTimeImmutable;
use EruoFood\Ai\Domain\Enum\MessageRole;
use EruoFood\Ai\Domain\ValueObject\AiMessage;

/** A single persisted turn within a {@see Conversation}. */
final readonly class ConversationMessage
{
    public function __construct(
        public MessageRole $role,
        public string $content,
        public DateTimeImmutable $createdAt,
    ) {
    }

    public function toAiMessage(): AiMessage
    {
        return new AiMessage($this->role, $this->content);
    }

    /** @return array{role: string, content: string, created_at: string} */
    public function toArray(): array
    {
        return [
            'role' => $this->role->value,
            'content' => $this->content,
            'created_at' => $this->createdAt->format(DATE_ATOM),
        ];
    }
}
