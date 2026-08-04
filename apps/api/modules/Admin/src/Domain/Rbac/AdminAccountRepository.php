<?php

declare(strict_types=1);

namespace EruoFood\Admin\Domain\Rbac;

/** Persistence port for the {@see AdminAccount} aggregate. */
interface AdminAccountRepository
{
    public function findByUserId(string $userId): ?AdminAccount;

    /** @return list<AdminAccount> */
    public function all(): array;

    public function save(AdminAccount $account): void;
}
