<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Interface\Http\Concerns;

use Illuminate\Http\Request;
use RuntimeException;

/** Reads the JWT-authenticated user id/roles the Identity middleware attaches. */
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

        return in_array('admin', $roles, true);
    }
}
