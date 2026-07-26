<?php

declare(strict_types=1);

namespace EruoFood\Ai\Infrastructure\Cache;

use EruoFood\Ai\Application\DTO\AiCompletionResult;
use EruoFood\Ai\Application\Port\AiResponseCache;

/** No-op cache used when AI response caching is disabled (e.g. in tests). */
final readonly class NullAiResponseCache implements AiResponseCache
{
    public function get(string $key): ?AiCompletionResult
    {
        return null;
    }

    public function put(string $key, AiCompletionResult $result, int $ttlSeconds): void
    {
    }

    public function forget(string $key): void
    {
    }
}
