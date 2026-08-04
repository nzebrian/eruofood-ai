<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/** Raised when a developer, application, API key or webhook is missing. */
final class PublicApiNotFound extends DomainException
{
    public static function of(string $resource, string $id): self
    {
        return new self(sprintf('%s "%s" was not found.', ucfirst($resource), $id));
    }

    public function errorCode(): string
    {
        return 'PUBLICAPI_RESOURCE_NOT_FOUND';
    }
}
