<?php

declare(strict_types=1);

namespace EruoFood\Verification\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

final class VerificationInvalidState extends DomainException
{
    public function errorCode(): string
    {
        return 'VERIFICATION_INVALID_STATE';
    }
}
