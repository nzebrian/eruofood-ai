<?php

declare(strict_types=1);

namespace EruoFood\Search\Interface\Http\Concerns;

use Illuminate\Http\Request;

/**
 * Reads the JWT-authenticated user id/roles the Identity middleware attaches.
 * Search has public endpoints too, so the user id is optional here.
 */
trait ResolvesAuthUser
{
    protected function optionalUserId(Request $request): ?string
    {
        $id = $request->attributes->get('auth_user_id');

        return is_string($id) ? $id : null;
    }

    protected function requireUserId(Request $request): string
    {
        $id = $this->optionalUserId($request);
        if ($id === null) {
            abort(401);
        }

        return $id;
    }

    protected function actorIsAdmin(Request $request): bool
    {
        /** @var list<string> $roles */
        $roles = $request->attributes->get('auth_roles', []);

        return in_array('admin', $roles, true);
    }
}
