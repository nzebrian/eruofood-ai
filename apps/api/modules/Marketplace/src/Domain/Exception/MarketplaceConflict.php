<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/** Raised on a uniqueness conflict (e.g. a duplicate vendor slug). */
final class MarketplaceConflict extends DomainException
{
    public function errorCode(): string
    {
        return 'MARKETPLACE_CONFLICT';
    }
}
