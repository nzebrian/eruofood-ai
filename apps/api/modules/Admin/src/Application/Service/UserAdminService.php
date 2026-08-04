<?php

declare(strict_types=1);

namespace EruoFood\Admin\Application\Service;

use EruoFood\Admin\Application\DTO\UserSummary;
use EruoFood\Admin\Application\Port\UserDirectory;
use EruoFood\Admin\Domain\Enum\AuditCategory;
use EruoFood\Admin\Domain\Event\AdminUserReinstated;
use EruoFood\Admin\Domain\Event\AdminUserSuspended;
use EruoFood\Admin\Domain\Exception\AdminNotFound;
use EruoFood\Shared\Domain\EventBus;
use EruoFood\Shared\Domain\Paginated;

/**
 * User Administration: search and moderate platform users. Admin owns no user
 * data — it reads through the {@see UserDirectory} port and effects changes by
 * publishing domain events ({@see AdminUserSuspended}/{@see AdminUserReinstated})
 * that Identity consumes to actually flip the account and revoke sessions.
 */
final readonly class UserAdminService
{
    public function __construct(
        private UserDirectory $users,
        private AuditService $audit,
        private EventBus $events,
    ) {
    }

    /**
     * @return Paginated<UserSummary>
     */
    public function search(?string $query, ?string $status, int $page, int $perPage): Paginated
    {
        return $this->users->search($query, $status, $page, $perPage);
    }

    public function get(string $userId): UserSummary
    {
        return $this->users->findById($userId) ?? throw AdminNotFound::of('user', $userId);
    }

    public function suspend(string $actorId, string $userId, string $reason): void
    {
        $this->get($userId);
        $this->audit->record($actorId, AuditCategory::Users, 'user.suspended', 'user', $userId, ['reason' => $reason]);
        $this->events->publish(new AdminUserSuspended($userId, $actorId, $reason));
    }

    public function reinstate(string $actorId, string $userId): void
    {
        $this->get($userId);
        $this->audit->record($actorId, AuditCategory::Users, 'user.reinstated', 'user', $userId);
        $this->events->publish(new AdminUserReinstated($userId, $actorId));
    }
}
