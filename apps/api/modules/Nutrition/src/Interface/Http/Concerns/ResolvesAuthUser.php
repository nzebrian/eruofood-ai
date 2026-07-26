<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Interface\Http\Concerns;

use Illuminate\Http\Request;
use RuntimeException;

/**
 * Reads the authenticated user id/roles that the Identity module's JWT
 * middleware attaches to the request. Kept module-local so Nutrition depends on
 * the request contract, not on Identity's internals.
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

    protected function actorIsAdmin(Request $request): bool
    {
        /** @var list<string> $roles */
        $roles = $request->attributes->get('auth_roles', []);

        return in_array('admin', $roles, true) || in_array('moderator', $roles, true);
    }
}
