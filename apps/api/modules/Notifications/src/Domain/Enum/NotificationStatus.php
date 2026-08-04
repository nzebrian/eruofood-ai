<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Domain\Enum;

/**
 * The lifecycle of a notification. Guarded by the aggregate: created → queued →
 * sent → delivered, or failed (and retried), and independently marked read.
 */
enum NotificationStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Failed = 'failed';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Pending => in_array($next, [self::Queued, self::Failed], true),
            self::Queued => in_array($next, [self::Sent, self::Failed], true),
            self::Sent => in_array($next, [self::Delivered, self::Failed], true),
            self::Failed => $next === self::Queued, // retry
            default => false,
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Delivered;
    }
}
