<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/** Raised when a loyalty account, reward, redemption or referral is missing. */
final class LoyaltyNotFound extends DomainException
{
    public static function of(string $resource, string $id): self
    {
        return new self(sprintf('%s "%s" was not found.', ucfirst($resource), $id));
    }

    public function errorCode(): string
    {
        return 'LOYALTY_RESOURCE_NOT_FOUND';
    }
}
