<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/** Raised on an invalid public-API operation (bad scope, malformed request). */
final class PublicApiInvalidState extends DomainException
{
    public function errorCode(): string
    {
        return 'PUBLICAPI_INVALID_STATE';
    }
}
