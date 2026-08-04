<?php

declare(strict_types=1);

namespace EruoFood\Ai\Tests\Support;

use EruoFood\Ai\Application\DTO\AiCompletionResult;
use EruoFood\Ai\Application\Port\AiResponseCache;

/** In-memory response cache. */
final class ArrayResponseCache implements AiResponseCache
{
    /** @var array<string, AiCompletionResult> */
    public array $store = [];

    public function get(string $key): ?AiCompletionResult
    {
        return $this->store[$key] ?? null;
    }

    public function put(string $key, AiCompletionResult $result, int $ttlSeconds): void
    {
        $this->store[$key] = $result;
    }

    public function forget(string $key): void
    {
        unset($this->store[$key]);
    }
}
