<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Infrastructure\Realtime;

use EruoFood\Notifications\Application\Port\RealtimeBroadcaster;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;

/**
 * Architecture-ready WebSocket broadcaster over Laravel's broadcasting layer
 * (Reverb/Pusher). Publishes `$event` with `$payload` to `$channel`. Enabled by
 * setting NOTIFICATIONS_REALTIME=reverb once a broadcast connection is
 * configured; the log broadcaster is the default so nothing external is required.
 */
final readonly class BroadcastingRealtimeBroadcaster implements RealtimeBroadcaster
{
    public function __construct(private BroadcastFactory $broadcast)
    {
    }

    public function broadcast(string $channel, string $event, array $payload): void
    {
        $this->broadcast->connection()->broadcast([$channel], $event, $payload);
    }
}
