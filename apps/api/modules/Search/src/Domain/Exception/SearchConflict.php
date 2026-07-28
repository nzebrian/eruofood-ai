<?php

declare(strict_types=1);

namespace EruoFood\Search\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/** Raised on a uniqueness conflict (e.g. a duplicate saved-search name). */
final class SearchConflict extends DomainException
{
    public function errorCode(): string
    {
        return 'SEARCH_CONFLICT';
    }
}
