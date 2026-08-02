<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/** Raised when API-key authentication fails. */
final class PublicApiUnauthenticated extends DomainException
{
    public function errorCode(): string
    {
        return 'PUBLICAPI_UNAUTHENTICATED';
    }
}
