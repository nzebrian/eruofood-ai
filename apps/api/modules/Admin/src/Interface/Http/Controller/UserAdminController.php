<?php

declare(strict_types=1);

namespace EruoFood\Admin\Interface\Http\Controller;

use EruoFood\Admin\Application\Service\AdminPresenter;
use EruoFood\Admin\Application\Service\PermissionService;
use EruoFood\Admin\Application\Service\UserAdminService;
use EruoFood\Admin\Domain\Rbac\Permission;
use EruoFood\Admin\Interface\Http\Concerns\AuthorizesAdmin;
use EruoFood\Admin\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** User Administration: search, view, suspend and reinstate platform users. */
final class UserAdminController
{
    use AuthorizesAdmin;
    use RespondsWithData;

    public function __construct(
        private readonly PermissionService $permissions,
        private readonly UserAdminService $users,
        private readonly AdminPresenter $presenter,
    ) {
    }

    protected function permissions(): PermissionService
    {
        return $this->permissions;
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request, Permission::USERS_READ);
        $query = $request->query('q');
        $status = $request->query('status');

        return $this->paginated(
            $this->users->search(
                is_string($query) ? $query : null,
                is_string($status) ? $status : null,
                (int) $request->query('page', '1'),
                (int) $request->query('per_page', '20'),
            ),
            fn ($u): array => $this->presenter->user($u),
        );
    }

    public function show(Request $request, string $userId): JsonResponse
    {
        $this->authorizeAdmin($request, Permission::USERS_READ);

        return $this->data($this->presenter->user($this->users->get($userId)));
    }

    public function suspend(Request $request, string $userId): JsonResponse
    {
        $actor = $this->authorizeAdmin($request, Permission::USERS_MODERATE);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $this->users->suspend($actor, $userId, $data['reason']);

        return $this->data(['user_id' => $userId, 'status' => 'suspended']);
    }

    public function reinstate(Request $request, string $userId): JsonResponse
    {
        $actor = $this->authorizeAdmin($request, Permission::USERS_MODERATE);
        $this->users->reinstate($actor, $userId);

        return $this->data(['user_id' => $userId, 'status' => 'active']);
    }
}
