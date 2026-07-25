<?php

declare(strict_types=1);

namespace EruoFood\Identity\Interface\Http\Controller;

use EruoFood\Identity\Application\Service\RegistrationService;
use EruoFood\Identity\Interface\Http\Concerns\ResolvesAuthUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class EmailVerificationController
{
    use ResolvesAuthUser;

    public function __construct(private RegistrationService $registration)
    {
    }

    /** Public: verify via the emailed uid + token. */
    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'uid' => ['required', 'string'],
            'token' => ['required', 'string'],
        ]);

        $this->registration->verifyEmail($validated['uid'], $validated['token']);

        return new JsonResponse(['data' => ['message' => 'Email verified.']]);
    }

    /** Authenticated: resend the verification email. */
    public function resend(Request $request): JsonResponse
    {
        $this->registration->resendVerification($this->currentUserId($request));

        return new JsonResponse(['data' => ['message' => 'Verification email sent.']], 202);
    }
}
