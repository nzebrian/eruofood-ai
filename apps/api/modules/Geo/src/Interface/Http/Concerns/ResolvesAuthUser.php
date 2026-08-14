<?php

declare(strict_types=1);

namespace EruoFood\Geo\Interface\Http\Concerns;

use Illuminate\Http\Request;
use RuntimeException;

/**
 * Reads the authenticated user the Identity JWT middleware attaches.
 *
 * Module-local, so Geo depends on the request contract rather than on Identity's
 * internals — the same choice Payments and Verification made.
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

    /** @return list<string> */
    protected function currentRoles(Request $request): array
    {
        /** @var list<string> $roles */
        $roles = $request->attributes->get('auth_roles', []);

        return $roles;
    }
}
