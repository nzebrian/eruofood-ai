<?php

declare(strict_types=1);

namespace EruoFood\Admin\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/** Raised when an admin account, CMS resource, setting, ticket, etc. is missing. */
final class AdminNotFound extends DomainException
{
    public static function of(string $resource, string $id): self
    {
        return new self(sprintf('%s "%s" was not found.', ucfirst($resource), $id));
    }

    public function errorCode(): string
    {
        return 'ADMIN_RESOURCE_NOT_FOUND';
    }
}
