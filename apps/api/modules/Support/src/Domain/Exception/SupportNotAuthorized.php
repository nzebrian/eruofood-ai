<?php

declare(strict_types=1);

namespace EruoFood\Support\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/** Raised when a caller acts on a ticket/resource they do not own or manage. */
final class SupportNotAuthorized extends DomainException
{
    public function errorCode(): string
    {
        return 'SUPPORT_NOT_AUTHORIZED';
    }
}
