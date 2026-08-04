<?php

declare(strict_types=1);

use EruoFood\PublicApi\Infrastructure\RateLimit\CacheRateLimiter;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Redis-outage resilience: when the cache backend throws (Redis unreachable),
 * the limiter must fail CLOSED — deny, never allow unlimited traffic — and never
 * let the exception escape as a 500.
 */
it('fails closed when the rate-limit backend is unavailable', function (): void {
    $throwing = new class () implements Cache {
        public function add($key, $value, $ttl = null): bool
        {
            throw new RuntimeException('Connection refused [tcp://127.0.0.1:6379]');
        }

        public function increment($key, $value = 1)
        {
            throw new RuntimeException('Connection refused');
        }

        // Unused Cache interface members for this test.
        public function has($key): bool
        {
            return false;
        }

        public function get($key, $default = null): mixed
        {
            return $default;
        }

        public function put($key, $value, $ttl = null): bool
        {
            return true;
        }

        public function decrement($key, $value = 1)
        {
            return 0;
        }

        public function forever($key, $value): bool
        {
            return true;
        }

        public function remember($key, $ttl, Closure $callback): mixed
        {
            return $callback();
        }

        public function sear($key, Closure $callback): mixed
        {
            return $callback();
        }

        public function rememberForever($key, Closure $callback): mixed
        {
            return $callback();
        }

        public function forget($key): bool
        {
            return true;
        }

        public function pull($key, $default = null): mixed
        {
            return $default;
        }

        public function many(array $keys): array
        {
            return [];
        }

        public function putMany(array $values, $ttl = null): bool
        {
            return true;
        }

        public function getStore()
        {
            return null;
        }

        public function clear(): bool
        {
            return true;
        }

        public function delete($key): bool
        {
            return true;
        }

        public function getMultiple($keys, $default = null): iterable
        {
            return [];
        }

        public function setMultiple($values, $ttl = null): bool
        {
            return true;
        }

        public function deleteMultiple($keys): bool
        {
            return true;
        }

        public function set($key, $value, $ttl = null): bool
        {
            return true;
        }
    };

    $limiter = new CacheRateLimiter($throwing);
    $result = $limiter->hit('publicapi:rl:app-1:min', 120, 60);

    expect($result->allowed)->toBeFalse();  // denied — no bypass
    expect($result->remaining)->toBe(0);
});
