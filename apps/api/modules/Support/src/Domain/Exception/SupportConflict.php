<?php

declare(strict_types=1);

namespace EruoFood\Support\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/** Raised on a uniqueness conflict (e.g. a duplicate article slug). */
final class SupportConflict extends DomainException
{
    public function errorCode(): string
    {
        return 'SUPPORT_CONFLICT';
    }
}
