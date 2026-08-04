<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/** Raised on a duplicate public-API operation (duplicate webhook, name clash). */
final class PublicApiConflict extends DomainException
{
    public function errorCode(): string
    {
        return 'PUBLICAPI_CONFLICT';
    }
}
