<?php

declare(strict_types=1);

namespace EruoFood\Identity\Application\Service;

use EruoFood\Identity\Application\DTO\UserProfileView;
use EruoFood\Identity\Application\Port\AuditRecorder;
use EruoFood\Identity\Domain\Exception\UserNotFound;
use EruoFood\Identity\Domain\Role\Role;
use EruoFood\Identity\Domain\User\User;
use EruoFood\Identity\Domain\User\UserRepository;
use EruoFood\Identity\Domain\ValueObject\UserId;
use EruoFood\Shared\Domain\Paginated;

/** Administrative use cases: list users and manage role assignments (RBAC). */
final readonly class UserAdminService
{
    public function __construct(
        private UserRepository $users,
        private UserPresenter $presenter,
        private AuditRecorder $audit,
    ) {
    }

    /**
     * @return Paginated<UserProfileView>
     */
    public function listUsers(int $page, int $perPage): Paginated
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));

        $result = $this->users->paginate($page, $perPage);

        $items = array_map(
            fn (User $user): UserProfileView => $this->presenter->present($user),
            $result->items,
        );

        return new Paginated($items, $result->total, $result->page, $result->perPage);
    }

    public function assignRole(string $actingAdminId, string $userId, string $role): UserProfileView
    {
        $user = $this->load($userId);
        $user->assignRole(Role::from($role));
        $this->users->save($user);
        $this->audit->record('roles.assigned', new UserId($actingAdminId), [
            'target' => $userId,
            'role' => $role,
        ]);

        return $this->presenter->present($user);
    }

    public function revokeRole(string $actingAdminId, string $userId, string $role): UserProfileView
    {
        $user = $this->load($userId);
        $user->revokeRole(Role::from($role));
        $this->users->save($user);
        $this->audit->record('roles.revoked', new UserId($actingAdminId), [
            'target' => $userId,
            'role' => $role,
        ]);

        return $this->presenter->present($user);
    }

    private function load(string $userId): User
    {
        return $this->users->findById(new UserId($userId)) ?? throw UserNotFound::forId($userId);
    }
}
