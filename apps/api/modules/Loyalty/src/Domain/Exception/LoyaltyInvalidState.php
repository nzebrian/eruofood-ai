<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/** Raised on an illegal loyalty operation (bad points amount, inactive reward, insufficient balance). */
final class LoyaltyInvalidState extends DomainException
{
    public function errorCode(): string
    {
        return 'LOYALTY_INVALID_STATE';
    }
}
