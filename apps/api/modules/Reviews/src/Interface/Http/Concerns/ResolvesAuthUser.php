<?php

declare(strict_types=1);

namespace EruoFood\Reviews\Interface\Http\Concerns;

use EruoFood\Reviews\Domain\Exception\ReviewsNotAuthorized;
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

    protected function isModerator(Request $request): bool
    {
        return array_intersect($this->roles($request), ['admin', 'support', 'moderator']) !== [];
    }

    /** Assert the caller is a moderator/admin, returning their id. */
    protected function requireModerator(Request $request): string
    {
        if (! $this->isModerator($request)) {
            throw new ReviewsNotAuthorized('Moderator role required.');
        }

        return $this->currentUserId($request);
    }

    /** Assert the caller may respond as a subject owner (vendor/admin), returning their id. */
    protected function requireResponder(Request $request): string
    {
        if (array_intersect($this->roles($request), ['admin', 'support', 'vendor', 'restaurant', 'rider']) === []) {
            throw new ReviewsNotAuthorized('Subject-owner role required to respond.');
        }

        return $this->currentUserId($request);
    }
}
