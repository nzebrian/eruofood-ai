<?php

declare(strict_types=1);

namespace EruoFood\Identity\Contracts;

/**
 * Minimal, stable projection of a user that other bounded contexts may depend
 * on. Intentionally small — it exposes only what cross-context callers need.
 */
final readonly class PublicUser
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
        public array $roles,
    ) {
    }
}
