<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Exception;

use EruoFood\Shared\Domain\Exception\DomainException;

/** Raised when a user acts on a wallet/payment/payout they do not own (and is not admin). */
final class PaymentsNotAuthorized extends DomainException
{
    public function errorCode(): string
    {
        return 'PAYMENTS_NOT_AUTHORIZED';
    }
}
