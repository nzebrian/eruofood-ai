<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Enum;

/** How a payment is funded. QR & USSD are architecture-ready. */
enum PaymentMethodType: string
{
    case Card = 'card';
    case BankTransfer = 'bank_transfer';
    case Wallet = 'wallet';
    case Qr = 'qr';     // architecture-ready
    case Ussd = 'ussd'; // architecture-ready
}
