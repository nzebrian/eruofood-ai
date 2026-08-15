<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/**
 * Somebody else won the race.
 *
 * Raised when a competing worker assigned this delivery, or claimed this rider,
 * between the candidate pool being built and the assignment being written. It
 * is the loud, correct outcome of the locking design: the loser is told, rather
 * than silently overwriting the winner.
 */
final class AssignmentConflict extends DomainException
{
    public static function riderTaken(string $riderId): self
    {
        return new self('That rider has just been assigned another delivery.');
    }

    public static function deliveryTaken(string $deliveryId): self
    {
        return new self('That delivery has just been assigned to another rider.');
    }

    public function errorCode(): string
    {
        return 'DISPATCH_ASSIGNMENT_CONFLICT';
    }
}
