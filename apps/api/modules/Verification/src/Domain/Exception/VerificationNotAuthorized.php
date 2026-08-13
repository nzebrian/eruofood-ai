<?php

declare(strict_types=1);

namespace EruoFood\Verification\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/**
 * The caller may not see or act on this verification.
 *
 * The message is deliberately uniform and says nothing about whether the case
 * exists: distinguishing "not yours" from "no such case" would turn this
 * endpoint into an oracle for probing other people's verification state.
 */
final class VerificationNotAuthorized extends DomainException
{
    public function __construct(string $message = 'You are not permitted to access this verification.')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'VERIFICATION_NOT_AUTHORIZED';
    }
}
