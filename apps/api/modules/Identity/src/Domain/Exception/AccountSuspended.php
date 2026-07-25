<?php

declare(strict_types=1);

namespace EruoFood\Identity\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

final class AccountSuspended extends DomainException
{
    public function __construct()
    {
        parent::__construct('This account has been suspended.');
    }

    public function errorCode(): string
    {
        return 'ACCOUNT_SUSPENDED';
    }
}
