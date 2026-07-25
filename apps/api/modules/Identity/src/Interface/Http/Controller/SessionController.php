<?php

declare(strict_types=1);

namespace EruoFood\Identity\Interface\Http\Controller;

use EruoFood\Identity\Application\Service\SessionService;
use EruoFood\Identity\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Identity\Interface\Http\Resource\SessionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Session management: list active sessions and revoke one. */
final readonly class SessionController
{
    use ResolvesAuthUser;

    public function __construct(private SessionService $sessions)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $sessions = $this->sessions->listSessions($this->currentUserId($request));

        return SessionResource::collection($sessions)->response();
    }

    public function destroy(Request $request, string $sessionId): JsonResponse
    {
        $this->sessions->revokeSession($this->currentUserId($request), $sessionId);

        return new JsonResponse(null, 204);
    }
}
