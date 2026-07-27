<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Interface\Http\Controller;

use EruoFood\Notifications\Application\Service\RealtimeService;
use EruoFood\Notifications\Domain\Enum\PresenceStatus;
use EruoFood\Notifications\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Notifications\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Real-time presence: heartbeat and presence lookups for the WebSocket layer. */
final readonly class PresenceController
{
    use RespondsWithData;
    use ResolvesAuthUser;

    public function __construct(private RealtimeService $realtime)
    {
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', 'in:online,away,offline']]);
        $this->realtime->heartbeat(
            $this->currentUserId($request),
            PresenceStatus::from((string) ($data['status'] ?? 'online')),
        );

        return new JsonResponse(null, 204);
    }

    public function show(Request $request): JsonResponse
    {
        $data = $request->validate(['user_ids' => ['array'], 'user_ids.*' => ['uuid']]);
        $ids = array_map('strval', $data['user_ids'] ?? [$this->currentUserId($request)]);

        return $this->data(['presence' => $this->realtime->presenceOfMany($ids)]);
    }
}
