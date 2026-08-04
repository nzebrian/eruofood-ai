<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\Exception;

/**
 * Raised when a value object or entity is constructed with an invalid argument
 * that violates a domain invariant.
 */
final class InvalidArgumentException extends DomainException
{
    public function errorCode(): string
    {
        return 'INVALID_ARGUMENT';
    }
}
