<?php

declare(strict_types=1);

namespace EruoFood\Admin\Domain\Rbac;

use DateTimeImmutable;
use EruoFood\Admin\Domain\Enum\AccountStatus;
use EruoFood\Admin\Domain\Enum\AdminRole;

/**
 * A back-office account for an Identity user — the aggregate root of the Admin
 * context's RBAC. It holds the user's admin roles plus any extra individual
 * permission grants, and a suspended/active status. Its {@see permissions()}
 * are the union of its roles' permissions and its extra grants; a SuperAdmin
 * implicitly has every permission.
 *
 * The account references the Identity user by id only (soft ref); Admin never
 * touches Identity's tables.
 */
final class AdminAccount
{
    /**
     * @param list<AdminRole> $roles
     * @param list<string> $extraPermissions
     */
    private function __construct(
        private readonly string $userId,
        private array $roles,
        private array $extraPermissions,
        private AccountStatus $status,
        private readonly DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * @param list<AdminRole> $roles
     */
    public static function grant(string $userId, array $roles, DateTimeImmutable $now): self
    {
        return new self($userId, $roles, [], AccountStatus::Active, $now);
    }

    /**
     * @param list<AdminRole> $roles
     * @param list<string> $extraPermissions
     */
    public static function reconstitute(
        string $userId,
        array $roles,
        array $extraPermissions,
        AccountStatus $status,
        DateTimeImmutable $createdAt,
    ): self {
        return new self($userId, $roles, $extraPermissions, $status, $createdAt);
    }

    /**
     * @param list<AdminRole> $roles
     */
    public function setRoles(array $roles): void
    {
        $this->roles = $roles;
    }

    public function grantPermission(string $permission): void
    {
        if (! in_array($permission, $this->extraPermissions, true)) {
            $this->extraPermissions[] = $permission;
        }
    }

    public function revokePermission(string $permission): void
    {
        $this->extraPermissions = array_values(array_filter(
            $this->extraPermissions,
            static fn (string $p): bool => $p !== $permission,
        ));
    }

    public function suspend(): void
    {
        $this->status = AccountStatus::Suspended;
    }

    public function activate(): void
    {
        $this->status = AccountStatus::Active;
    }

    public function isSuper(): bool
    {
        foreach ($this->roles as $role) {
            if ($role->isSuper()) {
                return true;
            }
        }

        return false;
    }

    public function can(string $permission): bool
    {
        if ($this->status !== AccountStatus::Active) {
            return false;
        }

        return $this->isSuper() || in_array($permission, $this->permissions(), true);
    }

    /** @return list<string> */
    public function permissions(): array
    {
        if ($this->isSuper()) {
            return Permission::all();
        }
        $permissions = $this->extraPermissions;
        foreach ($this->roles as $role) {
            $permissions = array_merge($permissions, Permission::forRole($role));
        }

        return array_values(array_unique($permissions));
    }

    public function userId(): string
    {
        return $this->userId;
    }

    /** @return list<AdminRole> */
    public function roles(): array
    {
        return $this->roles;
    }

    /** @return list<string> */
    public function extraPermissions(): array
    {
        return $this->extraPermissions;
    }

    public function status(): AccountStatus
    {
        return $this->status;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
