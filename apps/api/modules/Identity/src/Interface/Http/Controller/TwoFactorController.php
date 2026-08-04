<?php

declare(strict_types=1);

namespace EruoFood\Identity\Interface\Http\Controller;

use EruoFood\Identity\Application\Service\TwoFactorService;
use EruoFood\Identity\Interface\Http\Concerns\ResolvesAuthUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Authenticated 2FA management: enrol, confirm, disable. */
final readonly class TwoFactorController
{
    use ResolvesAuthUser;

    public function __construct(private TwoFactorService $twoFactor)
    {
    }

    public function enable(Request $request): JsonResponse
    {
        $enrollment = $this->twoFactor->enable($this->currentUserId($request));

        return new JsonResponse(['data' => [
            'secret' => $enrollment->secret,
            'provisioning_uri' => $enrollment->provisioningUri,
            'recovery_codes' => $enrollment->recoveryCodes,
        ]]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate(['code' => ['required', 'string']]);
        $this->twoFactor->confirm($this->currentUserId($request), $validated['code']);

        return new JsonResponse(['data' => ['message' => 'Two-factor authentication enabled.']]);
    }

    public function disable(Request $request): JsonResponse
    {
        $validated = $request->validate(['password' => ['required', 'string']]);
        $this->twoFactor->disable($this->currentUserId($request), $validated['password']);

        return new JsonResponse(['data' => ['message' => 'Two-factor authentication disabled.']]);
    }
}
