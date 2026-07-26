<?php

declare(strict_types=1);

namespace EruoFood\Ai\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/** Raised when a chat conversation cannot be found (or is not the caller's). */
final class ConversationNotFound extends DomainException
{
    public static function withId(string $id): self
    {
        return new self(sprintf('Conversation "%s" was not found.', $id));
    }

    public function errorCode(): string
    {
        return 'AI_CONVERSATION_NOT_FOUND';
    }
}
