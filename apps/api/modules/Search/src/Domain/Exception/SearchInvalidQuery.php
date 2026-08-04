<?php

declare(strict_types=1);

namespace EruoFood\Search\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/** Raised when a search request is malformed (bad pagination, geo, sort). */
final class SearchInvalidQuery extends DomainException
{
    public function errorCode(): string
    {
        return 'SEARCH_INVALID_QUERY';
    }
}
