<?php

declare(strict_types=1);

namespace EruoFood\Support\Interface\Http\Concerns;

use EruoFood\Support\Domain\Exception\SupportNotAuthorized;
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

    protected function isAgent(Request $request): bool
    {
        /** @var list<string> $roles */
        $roles = $request->attributes->get('auth_roles', []);

        return array_intersect($roles, ['admin', 'support', 'agent']) !== [];
    }

    /** Assert the caller is a support agent/admin, returning their id. */
    protected function requireAgent(Request $request): string
    {
        if (! $this->isAgent($request)) {
            throw new SupportNotAuthorized('Support agent role required.');
        }

        return $this->currentUserId($request);
    }
}
