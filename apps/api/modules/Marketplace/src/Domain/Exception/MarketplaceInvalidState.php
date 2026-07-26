<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/**
 * Raised on an invalid business-state transition — an illegal order-status move,
 * an empty checkout, an out-of-stock item, or ordering from a vendor that cannot
 * currently trade.
 */
final class MarketplaceInvalidState extends DomainException
{
    public function errorCode(): string
    {
        return 'MARKETPLACE_INVALID_STATE';
    }
}
