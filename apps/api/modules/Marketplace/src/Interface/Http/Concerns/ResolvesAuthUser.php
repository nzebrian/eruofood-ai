<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Interface\Http\Concerns;

use Illuminate\Http\Request;
use RuntimeException;

/**
 * Reads the authenticated user id/roles the Identity JWT middleware attaches to
 * the request. Module-local so the marketplace depends on the request contract,
 * not on Identity's internals.
 */
trait ResolvesAuthUser
{
    protected function currentUserId(Request $request): string
    {
        $id = $request->attributes->get('auth_user_id');
        if (! is_string($id)) {
            throw new RuntimeException('No authenticated user on the request.');
        }

        return $id;
    }

    protected function currentUserIdOrNull(Request $request): ?string
    {
        $id = $request->attributes->get('auth_user_id');

        return is_string($id) ? $id : null;
    }

    protected function actorIsAdmin(Request $request): bool
    {
        /** @var list<string> $roles */
        $roles = $request->attributes->get('auth_roles', []);

        return in_array('admin', $roles, true) || in_array('moderator', $roles, true);
    }
}
