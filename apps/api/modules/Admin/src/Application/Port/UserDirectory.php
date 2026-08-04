<?php

declare(strict_types=1);

namespace EruoFood\Admin\Application\Port;

use EruoFood\Admin\Application\DTO\UserSummary;
use EruoFood\Shared\Domain\Paginated;

/**
 * Read port over the Identity context's user directory. Admin queries users
 * for its administration screens through this abstraction; the infrastructure
 * adapter performs a soft read over Identity's data. Admin never mutates users
 * directly — moderation actions are published as domain events that Identity
 * consumes.
 */
interface UserDirectory
{
    public function findById(string $userId): ?UserSummary;

    /**
     * @return Paginated<UserSummary>
     */
    public function search(?string $query, ?string $status, int $page, int $perPage): Paginated;
}
