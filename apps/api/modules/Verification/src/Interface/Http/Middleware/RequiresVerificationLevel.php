<?php

declare(strict_types=1);

namespace EruoFood\Verification\Interface\Http\Middleware;

use Closure;
use EruoFood\Verification\Contracts\VerificationStatusQuery;
use EruoFood\Verification\Domain\Enum\VerificationLevel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route gate for progressive verification.
 *
 * Usage: `->middleware('requires.verification:identity')`, after `auth.jwt`.
 *
 * The 403 carries the level required and the current one, so a client can send
 * the user to the right flow instead of showing a dead end. A step-up demand is
 * a next step, not a failure.
 */
final readonly class RequiresVerificationLevel
{
    public function __construct(private VerificationStatusQuery $verification)
    {
    }

    public function handle(Request $request, Closure $next, string $level): Response
    {
        $userId = $request->attributes->get('auth_user_id');

        if (! is_string($userId) || $userId === '') {
            return new JsonResponse([
                'error' => ['code' => 'UNAUTHENTICATED', 'message' => 'Authentication required.'],
            ], 401);
        }

        $required = VerificationLevel::tryFrom($level);
        if ($required === null || $this->verification->meetsLevel($userId, $required->value)) {
            return $next($request);
        }

        return new JsonResponse([
            'error' => [
                'code' => 'VERIFICATION_STEP_UP_REQUIRED',
                'message' => sprintf('This action requires "%s" verification before it can proceed.', $required->value),
                'required_level' => $required->value,
                'current_level' => $this->verification->levelFor($userId),
            ],
        ], 403);
    }
}
