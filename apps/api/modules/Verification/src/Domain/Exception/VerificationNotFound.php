<?php

declare(strict_types=1);

namespace EruoFood\Verification\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

final class VerificationNotFound extends DomainException
{
    public static function of(string $what, string $id): self
    {
        return new self(sprintf('%s "%s" was not found.', ucfirst($what), $id));
    }

    public function errorCode(): string
    {
        return 'VERIFICATION_RESOURCE_NOT_FOUND';
    }
}
