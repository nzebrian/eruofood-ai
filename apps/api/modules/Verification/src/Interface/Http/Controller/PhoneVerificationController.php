<?php

declare(strict_types=1);

namespace EruoFood\Verification\Interface\Http\Controller;

use EruoFood\Verification\Application\Service\PhoneVerificationService;
use EruoFood\Verification\Contracts\VerificationStatusQuery;
use EruoFood\Verification\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Verification\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phone confirmation for the authenticated account.
 *
 * The number is always taken from the request and the account always from the
 * token, so one user can never confirm a number onto another's account.
 *
 * Neither endpoint reports whether a code exists or how many attempts remain
 * beyond what the caller needs: `confirm` answers verified or not. Counting down
 * attempts out loud tells a guesser exactly how much budget they have left.
 */
final readonly class PhoneVerificationController
{
    use RespondsWithData;
    use ResolvesAuthUser;

    public function __construct(
        private PhoneVerificationService $phones,
        private VerificationStatusQuery $verification,
    ) {
    }

    /** Send a code to a number. */
    public function request(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'min:7', 'max:32', 'regex:/^\+?[0-9 ()-]+$/'],
        ]);

        $this->phones->request($this->currentUserId($request), (string) $data['phone']);

        // No echo of the number: the response is read in logs, screenshots and
        // support tickets, and it tells the caller nothing they did not send.
        return $this->data(['sent' => true], 202);
    }

    /** Spend an attempt against the outstanding code. */
    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'min:4', 'max:10'],
        ]);

        $userId = $this->currentUserId($request);
        $verified = $this->phones->confirm($userId, (string) $data['code']);

        return $this->data([
            'verified' => $verified,
            'level' => $this->verification->levelFor($userId),
        ], $verified ? 200 : 422);
    }

    /** The account's current assurance level, for a client deciding what to prompt. */
    public function level(Request $request): JsonResponse
    {
        $userId = $this->currentUserId($request);

        return $this->data([
            'level' => $this->verification->levelFor($userId),
            'phone_verified' => $this->phones->isVerified($userId),
        ]);
    }
}
