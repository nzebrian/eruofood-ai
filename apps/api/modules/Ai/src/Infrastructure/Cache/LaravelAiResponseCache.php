<?php

declare(strict_types=1);

namespace EruoFood\Ai\Infrastructure\Cache;

use EruoFood\Ai\Application\DTO\AiCompletionResult;
use EruoFood\Ai\Application\Port\AiResponseCache;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Caches completion results in a Laravel cache store (Redis in production).
 *
 * Results are stored as plain arrays (not serialized objects) so the payload is
 * portable and inspectable, and rehydrated via {@see AiCompletionResult::fromArray()}.
 */
final readonly class LaravelAiResponseCache implements AiResponseCache
{
    public function __construct(
        private CacheRepository $cache,
        private string $prefix,
    ) {
    }

    public function get(string $key): ?AiCompletionResult
    {
        /** @var array<string, mixed>|null $data */
        $data = $this->cache->get($this->prefix.$key);

        return is_array($data) ? AiCompletionResult::fromArray($data) : null;
    }

    public function put(string $key, AiCompletionResult $result, int $ttlSeconds): void
    {
        $this->cache->put($this->prefix.$key, $result->toArray(), $ttlSeconds);
    }

    public function forget(string $key): void
    {
        $this->cache->forget($this->prefix.$key);
    }
}
