<?php

declare(strict_types=1);

namespace EruoFood\Identity\Application\Port;

use EruoFood\Identity\Domain\ValueObject\UserId;

/**
 * Short-lived store mapping a two-factor login challenge token to the user who
 * must complete it. Backed by the cache with a few-minutes TTL.
 */
interface LoginChallenges
{
    public function create(UserId $userId): string;

    public function resolve(string $challengeToken): ?UserId;

    public function forget(string $challengeToken): void;
}
