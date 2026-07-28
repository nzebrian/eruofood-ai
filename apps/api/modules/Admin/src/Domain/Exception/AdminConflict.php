<?php

declare(strict_types=1);

namespace EruoFood\Admin\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/** Raised on a uniqueness conflict (e.g. a duplicate page slug or setting key). */
final class AdminConflict extends DomainException
{
    public function errorCode(): string
    {
        return 'ADMIN_CONFLICT';
    }
}
