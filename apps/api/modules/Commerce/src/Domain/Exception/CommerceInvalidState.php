<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/**
 * Raised on an invalid business-state transition — an illegal order-status
 * move, an empty checkout, insufficient stock, an expired/exhausted coupon, or
 * ordering from a store that cannot currently trade.
 */
final class CommerceInvalidState extends DomainException
{
    public function errorCode(): string
    {
        return 'COMMERCE_INVALID_STATE';
    }
}
