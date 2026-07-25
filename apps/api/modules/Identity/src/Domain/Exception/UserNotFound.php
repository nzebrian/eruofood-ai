<?php

declare(strict_types=1);

namespace EruoFood\Identity\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

final class UserNotFound extends DomainException
{
    public static function forId(string $id): self
    {
        return new self(sprintf('User "%s" was not found.', $id));
    }

    public function errorCode(): string
    {
        return 'USER_NOT_FOUND';
    }
}
