<?php

declare(strict_types=1);

namespace EruoFood\Admin\Domain\Support;

/** The urgency of a support ticket, used to order the live queue. */
enum TicketPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';

    /** Sort weight — higher is more urgent. */
    public function weight(): int
    {
        return match ($this) {
            self::Low => 0,
            self::Normal => 1,
            self::High => 2,
            self::Urgent => 3,
        };
    }
}
