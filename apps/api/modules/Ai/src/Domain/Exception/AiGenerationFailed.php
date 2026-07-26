<?php

declare(strict_types=1);

namespace EruoFood\Ai\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;
use Throwable;

/** Raised when a provider call fails (network, auth, upstream error). */
final class AiGenerationFailed extends DomainException
{
    public static function because(string $reason, ?Throwable $previous = null): self
    {
        return new self(sprintf('AI generation failed: %s', $reason), 0, $previous);
    }

    public static function unparseable(): self
    {
        return new self('The AI returned a response that could not be parsed.');
    }

    public function errorCode(): string
    {
        return 'AI_GENERATION_FAILED';
    }
}
