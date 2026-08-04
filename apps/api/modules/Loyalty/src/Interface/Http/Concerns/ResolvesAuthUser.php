<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Interface\Http\Concerns;

use EruoFood\Loyalty\Domain\Exception\LoyaltyNotAuthorized;
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

    /** @return list<string> */
    protected function roles(Request $request): array
    {
        /** @var list<string> $roles */
        $roles = $request->attributes->get('auth_roles', []);

        return $roles;
    }

    protected function requireAdmin(Request $request): string
    {
        if (array_intersect($this->roles($request), ['admin', 'support', 'operations']) === []) {
            throw new LoyaltyNotAuthorized('Admin role required.');
        }

        return $this->currentUserId($request);
    }
}
