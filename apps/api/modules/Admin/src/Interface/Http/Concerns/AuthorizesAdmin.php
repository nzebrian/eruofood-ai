<?php

declare(strict_types=1);

namespace EruoFood\Admin\Interface\Http\Concerns;

use EruoFood\Admin\Application\Service\PermissionService;
use Illuminate\Http\Request;

/**
 * Fine-grained authorisation for admin controllers. The route middleware
 * (`auth.jwt`) proves identity; this asserts the caller holds the specific
 * {@see \EruoFood\Admin\Domain\Rbac\Permission} the action requires, throwing
 * {@see \EruoFood\Admin\Domain\Exception\AdminNotAuthorized} (→ 403) otherwise.
 *
 * The using controller must expose the resolved {@see PermissionService} via a
 * `permissions()` accessor.
 */
trait AuthorizesAdmin
{
    use ResolvesAuthUser;

    abstract protected function permissions(): PermissionService;

    /**
     * Assert the caller holds a permission and return their user id (the actor).
     */
    protected function authorizeAdmin(Request $request, string $permission): string
    {
        $userId = $this->currentUserId($request);
        $this->permissions()->authorize($userId, $permission, $this->actorIsPlatformAdmin($request));

        return $userId;
    }
}
