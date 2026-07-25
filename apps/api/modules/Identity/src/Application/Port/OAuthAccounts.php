<?php

declare(strict_types=1);

namespace EruoFood\Identity\Application\Port;

use EruoFood\Identity\Domain\ValueObject\UserId;

/** Links external provider identities (Google/Apple) to local users. */
interface OAuthAccounts
{
    public function findUserIdByProvider(string $provider, string $providerUserId): ?UserId;

    public function link(UserId $userId, string $provider, string $providerUserId): void;
}
