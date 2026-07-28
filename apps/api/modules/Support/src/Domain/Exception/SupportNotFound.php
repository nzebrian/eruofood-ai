<?php

declare(strict_types=1);

namespace EruoFood\Support\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/** Raised when a ticket, article, profile or rule is missing. */
final class SupportNotFound extends DomainException
{
    public static function of(string $resource, string $id): self
    {
        return new self(sprintf('%s "%s" was not found.', ucfirst($resource), $id));
    }

    public function errorCode(): string
    {
        return 'SUPPORT_RESOURCE_NOT_FOUND';
    }
}
