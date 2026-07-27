<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Application\Port;

use EruoFood\Notifications\Application\DTO\DeliveryOutcome;
use EruoFood\Notifications\Domain\Enum\NotificationChannel;
use EruoFood\Notifications\Domain\Notification\Notification;

/**
 * A delivery channel adapter (email, SMS, push, in-app, WhatsApp, Telegram).
 * The notification service resolves the right sender for a channel and asks it
 * to deliver; adapters never touch the domain beyond reading the notification.
 */
interface ChannelSender
{
    public function channel(): NotificationChannel;

    public function send(Notification $notification): DeliveryOutcome;
}
