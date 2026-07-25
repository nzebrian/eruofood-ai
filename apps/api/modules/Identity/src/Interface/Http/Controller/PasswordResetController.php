<?php

declare(strict_types=1);

namespace EruoFood\Identity\Interface\Http\Controller;

use EruoFood\Identity\Application\Service\PasswordService;
use EruoFood\Identity\Interface\Http\Request\ResetPasswordRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class PasswordResetController
{
    public function __construct(private PasswordService $passwords)
    {
    }

    /** Forgot password — always responds 202 to avoid account enumeration. */
    public function forgot(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => ['required', 'email']]);
        $this->passwords->requestReset($validated['email']);

        return new JsonResponse(['data' => [
            'message' => 'If an account exists for that email, a reset link has been sent.',
        ]], 202);
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $this->passwords->reset(
            email: (string) $request->string('email'),
            token: (string) $request->string('token'),
            newPassword: (string) $request->string('password'),
        );

        return new JsonResponse(['data' => ['message' => 'Password has been reset.']]);
    }
}
