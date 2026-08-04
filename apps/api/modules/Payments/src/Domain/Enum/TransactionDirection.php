<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Enum;

/** Whether a wallet transaction increases (credit) or decreases (debit) balance. */
enum TransactionDirection: string
{
    case Credit = 'credit';
    case Debit = 'debit';
}
