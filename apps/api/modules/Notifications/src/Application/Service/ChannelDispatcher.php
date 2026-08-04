<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Application\Service;

use EruoFood\Notifications\Application\DTO\DeliveryOutcome;
use EruoFood\Notifications\Application\Port\ChannelSender;
use EruoFood\Notifications\Domain\Enum\NotificationChannel;
use EruoFood\Notifications\Domain\Notification\Notification;

/**
 * Resolves the right {@see ChannelSender} for a notification's channel and
 * delivers through it. Holds only the channels that are enabled; a notification
 * for a disabled channel fails fast so it can be retried or dropped.
 */
final class ChannelDispatcher
{
    /** @var array<string, ChannelSender> */
    private array $senders = [];

    /**
     * @param iterable<ChannelSender> $senders
     */
    public function __construct(iterable $senders)
    {
        foreach ($senders as $sender) {
            $this->senders[$sender->channel()->value] = $sender;
        }
    }

    public function supports(NotificationChannel $channel): bool
    {
        return isset($this->senders[$channel->value]);
    }

    public function send(Notification $notification): DeliveryOutcome
    {
        $sender = $this->senders[$notification->channel()->value] ?? null;
        if ($sender === null) {
            return DeliveryOutcome::failed(sprintf('Channel "%s" is not enabled.', $notification->channel()->value));
        }

        return $sender->send($notification);
    }
}
