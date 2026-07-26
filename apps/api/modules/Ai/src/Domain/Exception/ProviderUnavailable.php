<?php

declare(strict_types=1);

namespace EruoFood\Ai\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/** Raised when no configured provider (default or fallback) can serve a request. */
final class ProviderUnavailable extends DomainException
{
    public static function named(string $name): self
    {
        return new self(sprintf('AI provider "%s" is not configured or available.', $name));
    }

    public static function allExhausted(): self
    {
        return new self('All configured AI providers are currently unavailable.');
    }

    public function errorCode(): string
    {
        return 'AI_PROVIDER_UNAVAILABLE';
    }
}
