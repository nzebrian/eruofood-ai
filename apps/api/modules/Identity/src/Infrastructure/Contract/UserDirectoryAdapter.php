<?php

declare(strict_types=1);

namespace EruoFood\Identity\Infrastructure\Contract;

use EruoFood\Identity\Contracts\PublicUser;
use EruoFood\Identity\Contracts\UserDirectory;
use EruoFood\Identity\Domain\Role\Role;
use EruoFood\Identity\Domain\User\UserRepository;
use EruoFood\Identity\Domain\ValueObject\Email;
use EruoFood\Identity\Domain\ValueObject\UserId;

/** Implements the module's public UserDirectory contract over the repository. */
final readonly class UserDirectoryAdapter implements UserDirectory
{
    public function __construct(private UserRepository $users)
    {
    }

    public function findById(string $userId): ?PublicUser
    {
        $user = $this->users->findById(new UserId($userId));

        if ($user === null) {
            return null;
        }

        return new PublicUser(
            id: $user->id()->value(),
            name: (string) $user->name(),
            email: (string) $user->email(),
            roles: array_map(static fn (Role $r): string => $r->value, $user->roles()),
        );
    }

    public function existsByEmail(string $email): bool
    {
        return $this->users->existsByEmail(new Email($email));
    }
}
