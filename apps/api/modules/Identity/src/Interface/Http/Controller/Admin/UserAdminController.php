<?php

declare(strict_types=1);

namespace EruoFood\Identity\Interface\Http\Controller\Admin;

use EruoFood\Identity\Application\DTO\UserProfileView;
use EruoFood\Identity\Application\Service\UserAdminService;
use EruoFood\Identity\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Identity\Interface\Http\Resource\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Admin-only user administration and role management (RBAC). */
final readonly class UserAdminController
{
    use ResolvesAuthUser;

    public function __construct(private UserAdminService $admin)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $page = (int) $request->integer('page', 1);
        $perPage = (int) $request->integer('per_page', 20);

        $result = $this->admin->listUsers($page, $perPage);

        return new JsonResponse([
            'data' => array_map(
                static fn (UserProfileView $v): array => UserResource::make($v)->resolve(),
                $result->items,
            ),
            'meta' => [
                'page' => $result->page,
                'per_page' => $result->perPage,
                'total' => $result->total,
            ],
        ]);
    }

    public function assignRole(Request $request, string $userId): JsonResponse
    {
        $validated = $request->validate(['role' => ['required', 'string', 'in:admin,moderator,user']]);

        $view = $this->admin->assignRole($this->currentUserId($request), $userId, $validated['role']);

        return UserResource::make($view)->response();
    }

    public function revokeRole(Request $request, string $userId): JsonResponse
    {
        $validated = $request->validate(['role' => ['required', 'string', 'in:admin,moderator,user']]);

        $view = $this->admin->revokeRole($this->currentUserId($request), $userId, $validated['role']);

        return UserResource::make($view)->response();
    }
}
