<?php

declare(strict_types=1);

namespace EruoFood\Admin\Interface\Http\Middleware;

use Closure;
use EruoFood\Admin\Application\Service\PermissionService;
use EruoFood\Admin\Domain\Exception\AdminNotAuthorized;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route gate for a specific back-office permission.
 *
 * Usage: `->middleware('permission:finance.read')`, after `auth.jwt`.
 *
 * This is the route-level counterpart to the {@see \EruoFood\Admin\Interface\Http\Concerns\AuthorizesAdmin}
 * trait that admin controllers use. It exists so contexts *outside* the Admin
 * module — Payments most of all — can gate on a real back-office permission
 * instead of the coarse `role:admin` check, which only knows the three Identity
 * roles and cannot distinguish a finance manager from a content editor.
 *
 * Only the permission name crosses the module boundary; callers depend on the
 * middleware alias, not on Admin's classes.
 */
final readonly class EnsurePermission
{
    public function __construct(private PermissionService $permissions)
    {
    }

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $userId = $request->attributes->get('auth_user_id');

        if (! is_string($userId) || $userId === '') {
            return new JsonResponse([
                'error' => ['code' => 'UNAUTHENTICATED', 'message' => 'Authentication required.'],
            ], 401);
        }

        /** @var list<string> $roles */
        $roles = $request->attributes->get('auth_roles', []);

        try {
            $this->permissions->authorize($userId, $permission, in_array('admin', $roles, true));
        } catch (AdminNotAuthorized $e) {
            return new JsonResponse([
                'error' => ['code' => $e->errorCode(), 'message' => $e->getMessage()],
            ], 403);
        }

        return $next($request);
    }
}
