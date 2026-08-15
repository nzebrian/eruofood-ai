<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/**
 * The requested transition is not one this state machine allows.
 *
 * Raised by the explicit transition tables rather than by an ordinal
 * comparison, so an illegal move is refused by name and not by arithmetic.
 */
final class DispatchInvalidState extends DomainException
{
    public static function transition(string $from, string $to): self
    {
        return new self(sprintf('A dispatch cannot move from "%s" to "%s".', $from, $to));
    }

    public function errorCode(): string
    {
        return 'DISPATCH_INVALID_STATE';
    }
}
