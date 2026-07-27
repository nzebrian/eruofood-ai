<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Infrastructure\Channel;

use EruoFood\Notifications\Application\DTO\DeliveryOutcome;
use EruoFood\Notifications\Application\Port\ChannelSender;
use EruoFood\Notifications\Domain\Enum\NotificationChannel;
use EruoFood\Notifications\Domain\Notification\Notification;
use Psr\Log\LoggerInterface;

/** Email delivery adapter. The default logs the send (offline/test-safe); a real Mailer/ESP adapter can replace it behind the ChannelSender port. */
final readonly class EmailChannelSender implements ChannelSender
{
    public function __construct(private LoggerInterface $log)
    {
    }

    public function channel(): NotificationChannel
    {
        return NotificationChannel::Email;
    }

    public function send(Notification $notification): DeliveryOutcome
    {
        $this->log->info('notifications.channel.Email', [
            'notification_id' => $notification->id(),
            'user_id' => $notification->userId(),
            'subject' => $notification->content()->subject,
        ]);

        return DeliveryOutcome::ok();
    }
}
