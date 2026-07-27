<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Infrastructure\Realtime;

use EruoFood\Notifications\Application\Port\RealtimeBroadcaster;
use Psr\Log\LoggerInterface;

/**
 * The default real-time broadcaster — logs each event instead of pushing over a
 * socket, so the whole system runs offline in tests/local. In production this is
 * swapped for a Laravel Reverb/Pusher adapter that publishes to the same channel
 * names (`user.{id}`, `conversation.{id}`, `presence`).
 */
final readonly class LogRealtimeBroadcaster implements RealtimeBroadcaster
{
    public function __construct(private LoggerInterface $log)
    {
    }

    public function broadcast(string $channel, string $event, array $payload): void
    {
        $this->log->info('notifications.realtime', ['channel' => $channel, 'event' => $event, 'payload' => $payload]);
    }
}
