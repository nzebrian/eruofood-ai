<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Application\Service;

use DateTimeImmutable;
use EruoFood\Notifications\Application\Port\PresenceRepository;
use EruoFood\Notifications\Application\Port\RealtimeBroadcaster;
use EruoFood\Notifications\Domain\Enum\PresenceStatus;

/**
 * Presence & live-status plumbing for the WebSocket layer: heartbeat-driven
 * presence, presence lookups, and a helper to push live order/delivery updates
 * to a user's private channel. Connection recovery is handled client-side by
 * re-subscribing; the server simply reports current presence on demand.
 */
final readonly class RealtimeService
{
    public function __construct(
        private PresenceRepository $presence,
        private RealtimeBroadcaster $realtime,
    ) {
    }

    public function heartbeat(string $userId, PresenceStatus $status): void
    {
        $this->presence->set($userId, $status, new DateTimeImmutable());
        $this->realtime->broadcast('presence', 'presence.updated', ['user_id' => $userId, 'status' => $status->value]);
    }

    public function presenceOf(string $userId): PresenceStatus
    {
        return $this->presence->get($userId);
    }

    /**
     * @param list<string> $userIds
     * @return array<string, string>
     */
    public function presenceOfMany(array $userIds): array
    {
        return $this->presence->statuses($userIds);
    }

    /**
     * Push a live domain update (order/delivery status) to a user's channel.
     *
     * @param array<string, mixed> $payload
     */
    public function pushLiveUpdate(string $userId, string $event, array $payload): void
    {
        $this->realtime->broadcast('user.'.$userId, $event, $payload);
    }
}
