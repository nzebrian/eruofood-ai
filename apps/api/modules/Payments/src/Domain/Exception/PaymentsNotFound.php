<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/** Raised when a payment, wallet, refund, settlement or payout is missing. */
final class PaymentsNotFound extends DomainException
{
    public static function of(string $resource, string $id): self
    {
        return new self(sprintf('%s "%s" was not found.', ucfirst($resource), $id));
    }

    public function errorCode(): string
    {
        return 'PAYMENTS_RESOURCE_NOT_FOUND';
    }
}
