<?php

declare(strict_types=1);

namespace EruoFood\Ai\Domain\ValueObject;

use EruoFood\Ai\Domain\Enum\MessageRole;
use EruoFood\Shared\Domain\Exception\InvalidArgumentException;

/**
 * A single chat message (role + text content).
 *
 * Immutable value object shared by the provider port, the conversation
 * aggregate and the gateway. Providers translate a list of these into their own
 * wire format, keeping provider-specific shapes out of the domain.
 */
final readonly class AiMessage
{
    public function __construct(
        public MessageRole $role,
        public string $content,
    ) {
        if (trim($content) === '') {
            throw new InvalidArgumentException('An AI message cannot be empty.');
        }
    }

    public static function system(string $content): self
    {
        return new self(MessageRole::System, $content);
    }

    public static function user(string $content): self
    {
        return new self(MessageRole::User, $content);
    }

    public static function assistant(string $content): self
    {
        return new self(MessageRole::Assistant, $content);
    }

    /** @return array{role: string, content: string} */
    public function toArray(): array
    {
        return ['role' => $this->role->value, 'content' => $this->content];
    }
}
