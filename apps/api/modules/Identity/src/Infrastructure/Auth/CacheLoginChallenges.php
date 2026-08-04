<?php

declare(strict_types=1);

namespace EruoFood\Identity\Infrastructure\Auth;

use EruoFood\Identity\Application\Port\LoginChallenges;
use EruoFood\Identity\Domain\ValueObject\UserId;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Str;

/** Two-factor login challenges held in the cache with a short TTL. */
final readonly class CacheLoginChallenges implements LoginChallenges
{
    private const PREFIX = 'identity:2fa_challenge:';
    private const TTL_SECONDS = 300;

    public function __construct(private Cache $cache)
    {
    }

    public function create(UserId $userId): string
    {
        $token = Str::random(48);
        $this->cache->put(self::PREFIX.$token, $userId->value(), self::TTL_SECONDS);

        return $token;
    }

    public function resolve(string $challengeToken): ?UserId
    {
        $value = $this->cache->get(self::PREFIX.$challengeToken);

        return is_string($value) ? new UserId($value) : null;
    }

    public function forget(string $challengeToken): void
    {
        $this->cache->forget(self::PREFIX.$challengeToken);
    }
}
