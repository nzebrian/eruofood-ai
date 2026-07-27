<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/** Raised on an invalid financial-state transition — an illegal status move, insufficient wallet balance, over-refund, or split mismatch. */
final class PaymentsInvalidState extends DomainException
{
    public function errorCode(): string
    {
        return 'PAYMENTS_INVALID_STATE';
    }
}
