<?php

declare(strict_types=1);

namespace EruoFood\Admin\Application\Service;

use DateTimeImmutable;
use EruoFood\Admin\Domain\Enum\AdminRole;
use EruoFood\Admin\Domain\Exception\AdminNotAuthorized;
use EruoFood\Admin\Domain\Rbac\AdminAccount;
use EruoFood\Admin\Domain\Rbac\AdminAccountRepository;
use EruoFood\Admin\Domain\Rbac\Permission;

/**
 * The authorisation core of the Admin context. It resolves the effective
 * {@see AdminAccount} for a request and answers permission checks. Two config
 * bootstraps grant super-admin without a stored account: an explicit id
 * allow-list (first Super Administrators), and — when enabled — any user the
 * Identity JWT already marks as a platform admin.
 */
final readonly class PermissionService
{
    /**
     * @param list<string> $bootstrapSuperAdmins
     */
    public function __construct(
        private AdminAccountRepository $accounts,
        private array $bootstrapSuperAdmins = [],
        private bool $identityAdminIsSuper = true,
    ) {
    }

    /**
     * The effective admin account for a user, or null if they have no back-office
     * access at all. `$isPlatformAdmin` comes from the caller's Identity token.
     */
    public function account(string $userId, bool $isPlatformAdmin = false): ?AdminAccount
    {
        if (in_array($userId, $this->bootstrapSuperAdmins, true)
            || ($isPlatformAdmin && $this->identityAdminIsSuper)) {
            return AdminAccount::grant($userId, [AdminRole::SuperAdmin], new DateTimeImmutable());
        }

        return $this->accounts->findByUserId($userId);
    }

    public function can(string $userId, string $permission, bool $isPlatformAdmin = false): bool
    {
        return $this->account($userId, $isPlatformAdmin)?->can($permission) ?? false;
    }

    /**
     * Assert the user holds a permission, returning their account for further
     * use. Throws {@see AdminNotAuthorized} otherwise.
     */
    public function authorize(string $userId, string $permission, bool $isPlatformAdmin = false): AdminAccount
    {
        $account = $this->account($userId, $isPlatformAdmin);
        if ($account === null || ! $account->can($permission)) {
            throw AdminNotAuthorized::missing($permission);
        }

        return $account;
    }

    /**
     * The full permission catalogue grouped for the UI.
     *
     * @return array<string, list<string>>
     */
    public function catalogue(): array
    {
        return Permission::groups();
    }
}
