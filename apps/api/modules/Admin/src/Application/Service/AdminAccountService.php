<?php

declare(strict_types=1);

namespace EruoFood\Admin\Application\Service;

use DateTimeImmutable;
use EruoFood\Admin\Domain\Enum\AdminRole;
use EruoFood\Admin\Domain\Enum\AuditCategory;
use EruoFood\Admin\Domain\Exception\AdminNotFound;
use EruoFood\Admin\Domain\Rbac\AdminAccount;
use EruoFood\Admin\Domain\Rbac\AdminAccountRepository;

/**
 * Manages back-office accounts and their role/permission grants. Every change
 * is audit-logged under the RBAC category. Assigning roles here is how a user
 * becomes an administrator; the account references the Identity user by id only.
 */
final readonly class AdminAccountService
{
    public function __construct(
        private AdminAccountRepository $accounts,
        private AuditService $audit,
    ) {
    }

    /**
     * @param list<AdminRole> $roles
     */
    public function grant(string $actorId, string $userId, array $roles): AdminAccount
    {
        $account = $this->accounts->findByUserId($userId) ?? AdminAccount::grant($userId, [], new DateTimeImmutable());
        $account->setRoles($roles);
        $this->accounts->save($account);
        $this->audit->record($actorId, AuditCategory::Rbac, 'admin_account.roles_set', 'admin_account', $userId, [
            'roles' => implode(',', array_map(static fn (AdminRole $r): string => $r->value, $roles)),
        ]);

        return $account;
    }

    public function grantPermission(string $actorId, string $userId, string $permission): AdminAccount
    {
        $account = $this->require($userId);
        $account->grantPermission($permission);
        $this->accounts->save($account);
        $this->audit->record($actorId, AuditCategory::Rbac, 'admin_account.permission_granted', 'admin_account', $userId, [
            'permission' => $permission,
        ]);

        return $account;
    }

    public function revokePermission(string $actorId, string $userId, string $permission): AdminAccount
    {
        $account = $this->require($userId);
        $account->revokePermission($permission);
        $this->accounts->save($account);
        $this->audit->record($actorId, AuditCategory::Rbac, 'admin_account.permission_revoked', 'admin_account', $userId, [
            'permission' => $permission,
        ]);

        return $account;
    }

    public function suspend(string $actorId, string $userId): AdminAccount
    {
        $account = $this->require($userId);
        $account->suspend();
        $this->accounts->save($account);
        $this->audit->record($actorId, AuditCategory::Rbac, 'admin_account.suspended', 'admin_account', $userId);

        return $account;
    }

    public function activate(string $actorId, string $userId): AdminAccount
    {
        $account = $this->require($userId);
        $account->activate();
        $this->accounts->save($account);
        $this->audit->record($actorId, AuditCategory::Rbac, 'admin_account.activated', 'admin_account', $userId);

        return $account;
    }

    /** @return list<AdminAccount> */
    public function all(): array
    {
        return $this->accounts->all();
    }

    public function find(string $userId): AdminAccount
    {
        return $this->require($userId);
    }

    private function require(string $userId): AdminAccount
    {
        return $this->accounts->findByUserId($userId) ?? throw AdminNotFound::of('admin account', $userId);
    }
}
