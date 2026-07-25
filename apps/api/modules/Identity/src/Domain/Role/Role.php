<?php

declare(strict_types=1);

namespace EruoFood\Identity\Domain\Role;

/**
 * The platform's roles. Modelled as an enum because the set is fixed and part
 * of the ubiquitous language. Each role maps to a set of Permissions (RBAC).
 */
enum Role: string
{
    case Admin = 'admin';
    case Moderator = 'moderator';
    case User = 'user';

    /**
     * Permissions granted by this role.
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Admin => Permission::cases(),
            self::Moderator => [
                Permission::ViewUsers,
                Permission::ModerateContent,
                Permission::ViewAuditLogs,
            ],
            self::User => [
                Permission::ManageOwnProfile,
            ],
        };
    }

    public function grants(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
