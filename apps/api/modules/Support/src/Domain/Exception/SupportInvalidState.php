<?php

declare(strict_types=1);

namespace EruoFood\Support\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/** Raised on an illegal ticket transition or a disallowed action. */
final class SupportInvalidState extends DomainException
{
    public function errorCode(): string
    {
        return 'SUPPORT_INVALID_STATE';
    }
}
