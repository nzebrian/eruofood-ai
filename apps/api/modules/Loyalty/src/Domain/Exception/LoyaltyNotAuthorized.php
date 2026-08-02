<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/** Raised when the caller may not perform a loyalty operation (admin-only action). */
final class LoyaltyNotAuthorized extends DomainException
{
    public function errorCode(): string
    {
        return 'LOYALTY_NOT_AUTHORIZED';
    }
}
