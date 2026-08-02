<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Interface\Http\Concerns;

use EruoFood\PublicApi\Application\Service\DeveloperService;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Resolves the developer account for the JWT-authenticated portal user. The
 * developer platform's management endpoints are protected by the normal internal
 * JWT auth (not API keys) — API keys are for the public data surface only.
 */
trait ResolvesDeveloper
{
    protected function currentUserId(Request $request): string
    {
        $id = $request->attributes->get('auth_user_id');
        if (! is_string($id)) {
            throw new RuntimeException('No authenticated user on the request.');
        }

        return $id;
    }

    protected function developerId(Request $request, DeveloperService $developers): string
    {
        return $developers->forUser($this->currentUserId($request))->id();
    }
}
