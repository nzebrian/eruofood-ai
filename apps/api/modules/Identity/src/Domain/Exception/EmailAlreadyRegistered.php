<?php

declare(strict_types=1);

namespace EruoFood\Identity\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

final class EmailAlreadyRegistered extends DomainException
{
    public function __construct()
    {
        parent::__construct('An account with this email already exists.');
    }

    public function errorCode(): string
    {
        return 'EMAIL_ALREADY_REGISTERED';
    }
}
