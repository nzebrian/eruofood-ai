<?php

declare(strict_types=1);

namespace EruoFood\Identity\Application\DTO;

use EruoFood\Identity\Domain\User\User;

/** Read model describing a user for API responses (never exposes the hash). */
final readonly class UserProfileView
{
    /**
     * @param list<string> $roles
     * @param list<string> $permissions
     * @param array<string, mixed> $preferences
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
        public ?string $phone,
        public bool $emailVerified,
        public ?string $avatarUrl,
        public array $roles,
        public array $permissions,
        public array $preferences,
        public bool $twoFactorEnabled,
        public string $status,
    ) {
    }

    /**
     * Map a User aggregate to its public view. `avatarUrl` is resolved by the
     * caller (which holds the AvatarStorage port).
     */
    public static function fromUser(User $user, ?string $avatarUrl): self
    {
        $permissions = [];
        foreach ($user->roles() as $role) {
            foreach ($role->permissions() as $permission) {
                $permissions[$permission->value] = true;
            }
        }

        return new self(
            id: $user->id()->value(),
            name: (string) $user->name(),
            email: (string) $user->email(),
            phone: $user->phone() !== null ? (string) $user->phone() : null,
            emailVerified: $user->hasVerifiedEmail(),
            avatarUrl: $avatarUrl,
            roles: array_map(static fn ($r): string => $r->value, $user->roles()),
            permissions: array_keys($permissions),
            preferences: $user->preferences(),
            twoFactorEnabled: $user->twoFactor()->isEnabled(),
            status: $user->status()->value,
        );
    }
}
