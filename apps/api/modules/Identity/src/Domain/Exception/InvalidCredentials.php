<?php

declare(strict_types=1);

namespace EruoFood\Identity\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

final class InvalidCredentials extends DomainException
{
    public function __construct()
    {
        // Deliberately vague to avoid leaking which factor was wrong.
        parent::__construct('The provided credentials are incorrect.');
    }

    public function errorCode(): string
    {
        return 'INVALID_CREDENTIALS';
    }
}
