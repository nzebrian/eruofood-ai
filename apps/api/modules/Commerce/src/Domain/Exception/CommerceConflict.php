<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/** Raised on a uniqueness conflict (duplicate store/product slug or coupon code). */
final class CommerceConflict extends DomainException
{
    public function errorCode(): string
    {
        return 'COMMERCE_CONFLICT';
    }
}
