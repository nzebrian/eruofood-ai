<?php

declare(strict_types=1);

namespace EruoFood\Ai\Domain\Exception;

use EruoFood\Ai\Domain\Enum\AiFeature;
use EruoFood\Shared\Domain\Exception\DomainException;

/** Raised when no active prompt template is configured for a feature. */
final class PromptNotFound extends DomainException
{
    public static function forFeature(AiFeature $feature): self
    {
        return new self(sprintf('No active prompt template for feature "%s".', $feature->value));
    }

    public static function byId(string $id): self
    {
        return new self(sprintf('Prompt template "%s" was not found.', $id));
    }

    public function errorCode(): string
    {
        return 'AI_PROMPT_NOT_FOUND';
    }
}
