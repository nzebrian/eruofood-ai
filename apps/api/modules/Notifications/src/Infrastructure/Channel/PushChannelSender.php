<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Infrastructure\Channel;

use EruoFood\Notifications\Application\DTO\DeliveryOutcome;
use EruoFood\Notifications\Application\Port\ChannelSender;
use EruoFood\Notifications\Domain\Enum\NotificationChannel;
use EruoFood\Notifications\Domain\Notification\Notification;
use Psr\Log\LoggerInterface;

/** Push-notification adapter (FCM/APNs). Logs by default; swap for a real push service behind the port. */
final readonly class PushChannelSender implements ChannelSender
{
    public function __construct(private LoggerInterface $log)
    {
    }

    public function channel(): NotificationChannel
    {
        return NotificationChannel::Push;
    }

    public function send(Notification $notification): DeliveryOutcome
    {
        $this->log->info('notifications.channel.Push', [
            'notification_id' => $notification->id(),
            'user_id' => $notification->userId(),
            'subject' => $notification->content()->subject,
        ]);

        return DeliveryOutcome::ok();
    }
}
