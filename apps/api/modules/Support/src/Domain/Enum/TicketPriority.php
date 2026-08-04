<?php

declare(strict_types=1);

namespace EruoFood\Support\Domain\Enum;

/** Ticket urgency — drives SLA targets, queue ordering and escalation. */
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

    /** The next level up, for escalation (Urgent stays Urgent). */
    public function escalated(): self
    {
        return match ($this) {
            self::Low => self::Normal,
            self::Normal => self::High,
            self::High, self::Urgent => self::Urgent,
        };
    }
}
