<?php

declare(strict_types=1);

namespace EruoFood\Verification\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/** A verification is already open for this subject, or the resource already exists. */
final class VerificationConflict extends DomainException
{
    public function errorCode(): string
    {
        return 'VERIFICATION_CONFLICT';
    }
}
