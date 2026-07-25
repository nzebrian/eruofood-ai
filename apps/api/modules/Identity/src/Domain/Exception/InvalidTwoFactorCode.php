<?php

declare(strict_types=1);

namespace EruoFood\Identity\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

final class InvalidTwoFactorCode extends DomainException
{
    public function __construct()
    {
        parent::__construct('The two-factor code is invalid or has expired.');
    }

    public function errorCode(): string
    {
        return 'INVALID_TWO_FACTOR_CODE';
    }
}
