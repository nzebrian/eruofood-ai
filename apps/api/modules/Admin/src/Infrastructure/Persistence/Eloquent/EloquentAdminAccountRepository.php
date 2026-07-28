<?php

declare(strict_types=1);

namespace EruoFood\Admin\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Admin\Domain\Enum\AccountStatus;
use EruoFood\Admin\Domain\Enum\AdminRole;
use EruoFood\Admin\Domain\Rbac\AdminAccount;
use EruoFood\Admin\Domain\Rbac\AdminAccountRepository;
use EruoFood\Admin\Infrastructure\Persistence\Eloquent\Model\AdminAccountModel;

final class EloquentAdminAccountRepository implements AdminAccountRepository
{
    public function findByUserId(string $userId): ?AdminAccount
    {
        $m = AdminAccountModel::query()->find($userId);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function all(): array
    {
        return array_map(
            fn (AdminAccountModel $m): AdminAccount => $this->toDomain($m),
            AdminAccountModel::query()->orderBy('created_at')->get()->all(),
        );
    }

    public function save(AdminAccount $account): void
    {
        $model = AdminAccountModel::query()->find($account->userId()) ?? new AdminAccountModel();
        $model->user_id = $account->userId();
        $model->roles = array_map(static fn (AdminRole $r): string => $r->value, $account->roles());
        $model->extra_permissions = $account->extraPermissions();
        $model->status = $account->status()->value;
        $model->created_at = $account->createdAt();
        $model->save();
    }

    private function toDomain(AdminAccountModel $m): AdminAccount
    {
        /** @var list<string> $roles */
        $roles = $m->roles ?? [];
        /** @var list<string> $extra */
        $extra = $m->extra_permissions ?? [];

        return AdminAccount::reconstitute(
            userId: $m->user_id,
            roles: array_values(array_filter(array_map(
                static fn (string $r): ?AdminRole => AdminRole::tryFrom($r),
                $roles,
            ))),
            extraPermissions: $extra,
            status: AccountStatus::from($m->status),
            createdAt: DateTimeImmutable::createFromInterface($m->created_at),
        );
    }
}
