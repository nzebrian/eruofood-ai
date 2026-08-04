<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Application\Port;

/**
 * Pushes real-time events to subscribers over WebSockets (Laravel Reverb /
 * Pusher-style). Used for live order/delivery updates, live chat, typing
 * indicators and presence. A port so the transport (Reverb, a log stub for
 * tests, or a third party) is swappable.
 */
interface RealtimeBroadcaster
{
    /**
     * @param array<string, mixed> $payload
     */
    public function broadcast(string $channel, string $event, array $payload): void;
}
