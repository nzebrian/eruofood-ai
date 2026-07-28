<?php

declare(strict_types=1);

namespace EruoFood\Admin\Interface\Http\Controller;

use EruoFood\Admin\Application\Service\AdminAccountService;
use EruoFood\Admin\Application\Service\AdminPresenter;
use EruoFood\Admin\Application\Service\ImpersonationService;
use EruoFood\Admin\Application\Service\PermissionService;
use EruoFood\Admin\Domain\Enum\AdminRole;
use EruoFood\Admin\Domain\Rbac\Permission;
use EruoFood\Admin\Interface\Http\Concerns\AuthorizesAdmin;
use EruoFood\Admin\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Role & Permission Management: admin accounts, the permission catalogue, and impersonation. */
final class RbacController
{
    use AuthorizesAdmin;
    use RespondsWithData;

    public function __construct(
        private readonly PermissionService $permissions,
        private readonly AdminAccountService $accounts,
        private readonly ImpersonationService $impersonations,
        private readonly AdminPresenter $presenter,
    ) {
    }

    protected function permissions(): PermissionService
    {
        return $this->permissions;
    }

    public function catalogue(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request, Permission::RBAC_MANAGE);

        return $this->data([
            'permissions' => Permission::all(),
            'groups' => $this->permissions->catalogue(),
            'roles' => array_map(
                static fn (AdminRole $r): array => [
                    'value' => $r->value,
                    'label' => $r->label(),
                    'permissions' => Permission::forRole($r),
                ],
                AdminRole::cases(),
            ),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request, Permission::RBAC_MANAGE);

        return $this->data(['accounts' => array_map(
            fn ($a): array => $this->presenter->account($a),
            $this->accounts->all(),
        )]);
    }

    public function show(Request $request, string $userId): JsonResponse
    {
        $this->authorizeAdmin($request, Permission::RBAC_MANAGE);

        return $this->data($this->presenter->account($this->accounts->find($userId)));
    }

    public function setRoles(Request $request, string $userId): JsonResponse
    {
        $actor = $this->authorizeAdmin($request, Permission::RBAC_MANAGE);
        $data = $request->validate([
            'roles' => ['required', 'array'],
            'roles.*' => ['string', 'in:'.implode(',', array_map(static fn (AdminRole $r): string => $r->value, AdminRole::cases()))],
        ]);
        $roles = array_map(static fn (string $r): AdminRole => AdminRole::from($r), $data['roles']);

        return $this->data($this->presenter->account($this->accounts->grant($actor, $userId, $roles)));
    }

    public function grantPermission(Request $request, string $userId): JsonResponse
    {
        $actor = $this->authorizeAdmin($request, Permission::RBAC_MANAGE);
        $data = $request->validate(['permission' => ['required', 'string', 'in:'.implode(',', Permission::all())]]);

        return $this->data($this->presenter->account($this->accounts->grantPermission($actor, $userId, $data['permission'])));
    }

    public function revokePermission(Request $request, string $userId): JsonResponse
    {
        $actor = $this->authorizeAdmin($request, Permission::RBAC_MANAGE);
        $data = $request->validate(['permission' => ['required', 'string']]);

        return $this->data($this->presenter->account($this->accounts->revokePermission($actor, $userId, $data['permission'])));
    }

    public function suspend(Request $request, string $userId): JsonResponse
    {
        $actor = $this->authorizeAdmin($request, Permission::RBAC_MANAGE);

        return $this->data($this->presenter->account($this->accounts->suspend($actor, $userId)));
    }

    public function activate(Request $request, string $userId): JsonResponse
    {
        $actor = $this->authorizeAdmin($request, Permission::RBAC_MANAGE);

        return $this->data($this->presenter->account($this->accounts->activate($actor, $userId)));
    }

    public function startImpersonation(Request $request): JsonResponse
    {
        $actor = $this->authorizeAdmin($request, Permission::IMPERSONATE);
        $data = $request->validate([
            'target_user_id' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $impersonation = $this->impersonations->start($actor, $data['target_user_id'], $data['reason']);

        return $this->data($this->presenter->impersonation($impersonation), 201);
    }

    public function endImpersonation(Request $request, string $id): JsonResponse
    {
        $actor = $this->authorizeAdmin($request, Permission::IMPERSONATE);

        return $this->data($this->presenter->impersonation($this->impersonations->end($actor, $id)));
    }
}
