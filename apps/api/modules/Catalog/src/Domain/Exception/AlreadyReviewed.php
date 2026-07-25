<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

final class AlreadyReviewed extends DomainException
{
    public function __construct()
    {
        parent::__construct('You have already reviewed this recipe.');
    }

    public function errorCode(): string
    {
        return 'ALREADY_REVIEWED';
    }
}
