<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Infrastructure\Channel;

use EruoFood\Notifications\Application\DTO\DeliveryOutcome;
use EruoFood\Notifications\Application\Port\ChannelSender;
use EruoFood\Notifications\Domain\Enum\NotificationChannel;
use EruoFood\Notifications\Domain\Notification\Notification;

/**
 * In-app delivery: the persisted {@see Notification} row *is* the notification
 * centre entry, so "sending" simply confirms success. The real-time toast is
 * pushed separately by the notification service via the broadcaster.
 */
final class InAppChannelSender implements ChannelSender
{
    public function channel(): NotificationChannel
    {
        return NotificationChannel::InApp;
    }

    public function send(Notification $notification): DeliveryOutcome
    {
        return DeliveryOutcome::ok('stored');
    }
}
