<?php

declare(strict_types=1);

namespace EruoFood\Ai\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/** Raised when a user exceeds their AI request allowance for the window. */
final class RateLimitExceeded extends DomainException
{
    public static function retryAfter(int $seconds): self
    {
        return new self(sprintf('AI request limit reached. Try again in %d seconds.', $seconds));
    }

    public function errorCode(): string
    {
        return 'AI_RATE_LIMIT_EXCEEDED';
    }
}
