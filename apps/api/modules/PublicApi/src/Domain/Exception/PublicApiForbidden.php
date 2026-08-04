<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/** Raised when the API key lacks a required scope or owns a different resource. */
final class PublicApiForbidden extends DomainException
{
    public function errorCode(): string
    {
        return 'PUBLICAPI_FORBIDDEN';
    }
}
