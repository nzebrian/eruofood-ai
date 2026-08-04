<?php

declare(strict_types=1);

namespace EruoFood\Identity\Interface\Http\Concerns;

use EruoFood\Identity\Application\DTO\AuthResult;
use EruoFood\Identity\Interface\Http\Resource\UserResource;
use Illuminate\Http\JsonResponse;

/** Formats an AuthResult into the standard API envelope. */
trait BuildsAuthResponse
{
    protected function authResponse(AuthResult $result, int $status = 200): JsonResponse
    {
        if ($result->twoFactorRequired) {
            return new JsonResponse([
                'data' => [
                    'two_factor_required' => true,
                    'challenge_token' => $result->challengeToken,
                ],
            ], 200);
        }

        return new JsonResponse([
            'data' => [
                'user' => UserResource::make($result->user)->resolve(),
                'tokens' => [
                    'access_token' => $result->tokens?->accessToken,
                    'token_type' => $result->tokens?->tokenType,
                    'expires_in' => $result->tokens?->expiresIn,
                    'refresh_token' => $result->tokens?->refreshToken,
                ],
            ],
        ], $status);
    }
}
