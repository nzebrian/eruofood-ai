<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/** Raised on a duplicate loyalty operation (already referred, duplicate reward code). */
final class LoyaltyConflict extends DomainException
{
    public function errorCode(): string
    {
        return 'LOYALTY_CONFLICT';
    }
}
