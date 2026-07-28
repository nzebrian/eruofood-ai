<?php

declare(strict_types=1);

namespace EruoFood\Search\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/** Raised when a caller searches an admin-only scope without permission. */
final class SearchNotAuthorized extends DomainException
{
    public function errorCode(): string
    {
        return 'SEARCH_NOT_AUTHORIZED';
    }
}
