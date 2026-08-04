<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/** Raised on a uniqueness conflict — a duplicate idempotency key or an already-processed webhook. */
final class PaymentsConflict extends DomainException
{
    public function errorCode(): string
    {
        return 'PAYMENTS_CONFLICT';
    }
}
