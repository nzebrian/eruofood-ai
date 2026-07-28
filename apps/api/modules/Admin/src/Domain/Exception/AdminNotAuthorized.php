<?php

declare(strict_types=1);

namespace EruoFood\Admin\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/** Raised when an admin lacks the permission required for an action. */
final class AdminNotAuthorized extends DomainException
{
    public static function missing(string $permission): self
    {
        return new self(sprintf('This action requires the "%s" permission.', $permission));
    }

    public function errorCode(): string
    {
        return 'ADMIN_NOT_AUTHORIZED';
    }
}
