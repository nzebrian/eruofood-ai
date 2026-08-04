<?php

declare(strict_types=1);

namespace EruoFood\Identity\Interface\Http\Concerns;

use Illuminate\Http\Request;
use RuntimeException;

/** Helper for controllers to read the authenticated user id set by middleware. */
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
}
