<?php

declare(strict_types=1);

namespace EruoFood\Admin\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/** Raised on an invalid state transition or a disallowed admin action. */
final class AdminInvalidState extends DomainException
{
    public function errorCode(): string
    {
        return 'ADMIN_INVALID_STATE';
    }
}
