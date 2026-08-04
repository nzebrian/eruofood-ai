<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/** Raised when a payment provider is unavailable or returns an error. */
final class ProviderError extends DomainException
{
    public function errorCode(): string
    {
        return 'PAYMENTS_PROVIDER_ERROR';
    }
}
