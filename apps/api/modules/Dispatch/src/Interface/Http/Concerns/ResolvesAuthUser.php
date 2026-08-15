<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Interface\Http\Concerns;

use Illuminate\Http\Request;
use RuntimeException;

/**
 * Reads the authenticated user the Identity JWT middleware attaches.
 *
 * Module-local, so Dispatch depends on the request contract rather than on
 * Identity's internals — the same choice Geo, Payments and Verification made.
 *
 * There is no method here that reads a rider id from the request. That is the
 * point: a rider id in a payload is a claim, and every rider-facing service
 * resolves it from the authenticated account instead. Rider self-assignment is
 * impossible if the request can never say who the rider is.
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
}
