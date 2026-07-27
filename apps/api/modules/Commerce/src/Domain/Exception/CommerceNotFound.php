<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/** Raised when a commerce resource (store, product, order, coupon…) is missing. */
final class CommerceNotFound extends DomainException
{
    public static function of(string $resource, string $id): self
    {
        return new self(sprintf('%s "%s" was not found.', ucfirst($resource), $id));
    }

    public function errorCode(): string
    {
        return 'COMMERCE_RESOURCE_NOT_FOUND';
    }
}
