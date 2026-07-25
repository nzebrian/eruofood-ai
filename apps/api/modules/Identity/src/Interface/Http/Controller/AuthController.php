<?php

declare(strict_types=1);

namespace EruoFood\Identity\Interface\Http\Controller;

use EruoFood\Identity\Application\DTO\SessionMetadata;
use EruoFood\Identity\Application\Service\AuthenticationService;
use EruoFood\Identity\Application\Service\RegistrationService;
use EruoFood\Identity\Interface\Http\Concerns\BuildsAuthResponse;
use EruoFood\Identity\Interface\Http\Request\LoginRequest;
use EruoFood\Identity\Interface\Http\Request\RegisterRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Authentication endpoints: register, login, 2FA challenge, social, refresh, logout. */
final readonly class AuthController
{
    use BuildsAuthResponse;

    public function __construct(
        private RegistrationService $registration,
        private AuthenticationService $authentication,
    ) {
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        $result = $this->registration->register(
            name: (string) $request->string('name'),
            email: (string) $request->string('email'),
            password: (string) $request->string('password'),
            meta: $this->meta($request),
        );

        return $this->authResponse($result, 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authentication->loginWithPassword(
            email: (string) $request->string('email'),
            password: (string) $request->string('password'),
            meta: $this->meta($request),
        );

        return $this->authResponse($result);
    }

    public function twoFactorChallenge(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'challenge_token' => ['required', 'string'],
            'code' => ['required', 'string'],
        ]);

        $result = $this->authentication->completeTwoFactorLogin(
            challengeToken: $validated['challenge_token'],
            code: $validated['code'],
            meta: $this->meta($request),
        );

        return $this->authResponse($result);
    }

    public function social(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['required', 'string', 'in:google,apple'],
            'id_token' => ['required', 'string'],
        ]);

        $result = $this->authentication->loginWithSocial(
            provider: $validated['provider'],
            idToken: $validated['id_token'],
            meta: $this->meta($request),
        );

        return $this->authResponse($result);
    }

    public function refresh(Request $request): JsonResponse
    {
        $validated = $request->validate(['refresh_token' => ['required', 'string']]);

        $tokens = $this->authentication->refresh($validated['refresh_token'], $this->meta($request));

        return new JsonResponse(['data' => [
            'access_token' => $tokens->accessToken,
            'token_type' => $tokens->tokenType,
            'expires_in' => $tokens->expiresIn,
            'refresh_token' => $tokens->refreshToken,
        ]]);
    }

    public function logout(Request $request): JsonResponse
    {
        $validated = $request->validate(['refresh_token' => ['required', 'string']]);
        $this->authentication->logout($validated['refresh_token']);

        return new JsonResponse(null, 204);
    }

    private function meta(Request $request): SessionMetadata
    {
        return new SessionMetadata($request->ip(), $request->userAgent());
    }
}
