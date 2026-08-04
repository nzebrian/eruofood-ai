<?php

declare(strict_types=1);

namespace EruoFood\Ai\Application\Port;

use EruoFood\Ai\Application\DTO\AiCompletionResult;

/**
 * Caches completion results so identical (feature + rendered prompt + model)
 * requests avoid a repeat provider call. Backed by a Laravel cache store in
 * infrastructure; a no-op decorator is used when caching is disabled.
 */
interface AiResponseCache
{
    public function get(string $key): ?AiCompletionResult;

    public function put(string $key, AiCompletionResult $result, int $ttlSeconds): void;

    public function forget(string $key): void;
}
