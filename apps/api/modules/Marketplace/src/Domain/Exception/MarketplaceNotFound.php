<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/** Raised when a marketplace resource (vendor, item, order, delivery…) is missing. */
final class MarketplaceNotFound extends DomainException
{
    public static function of(string $resource, string $id): self
    {
        return new self(sprintf('%s "%s" was not found.', ucfirst($resource), $id));
    }

    public function errorCode(): string
    {
        return 'MARKETPLACE_RESOURCE_NOT_FOUND';
    }
}
