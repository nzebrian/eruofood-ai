<?php

declare(strict_types=1);

namespace EruoFood\Support\Domain\Enum;

/**
 * The lifecycle state of a support ticket. The workflow is:
 *   New → Open → (Pending ⇄ OnHold) → Resolved → Closed, with Resolved/Closed
 * reopenable. {@see canTransitionTo()} is the single source of truth for legal
 * moves so the aggregate and the UI agree.
 */
enum TicketStatus: string
{
    case New = 'new';           // created, unassigned, no agent response yet
    case Open = 'open';         // an agent is actively working it
    case Pending = 'pending';   // waiting on the customer
    case OnHold = 'on_hold';    // waiting on a third party / internal blocker
    case Resolved = 'resolved'; // answered, awaiting confirmation / CSAT
    case Closed = 'closed';     // done

    public function isTerminal(): bool
    {
        return $this === self::Resolved || $this === self::Closed;
    }

    public function isOpen(): bool
    {
        return ! $this->isTerminal();
    }

    public function canTransitionTo(self $target): bool
    {
        if ($this === $target) {
            return true;
        }

        return match ($this) {
            self::New => in_array($target, [self::Open, self::Pending, self::OnHold, self::Resolved, self::Closed], true),
            self::Open, self::Pending, self::OnHold => in_array($target, [self::Open, self::Pending, self::OnHold, self::Resolved, self::Closed], true),
            self::Resolved => in_array($target, [self::Open, self::Closed], true),   // reopen or close
            self::Closed => $target === self::Open,                                   // reopen only
        };
    }
}
