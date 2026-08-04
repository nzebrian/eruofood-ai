<?php

declare(strict_types=1);

namespace EruoFood\Identity\Contracts;

/**
 * The Identity module's published API. Other modules resolve this from the
 * container to look up users — they never import Identity's internal domain,
 * application, or infrastructure classes (Modular Monolith boundary rule).
 */
interface UserDirectory
{
    public function findById(string $userId): ?PublicUser;

    public function existsByEmail(string $email): bool;
}
