<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Enum;

/** The business meaning of a wallet/ledger transaction. */
enum TransactionType: string
{
    case Payment = 'payment';
    case Refund = 'refund';
    case Topup = 'topup';
    case Withdrawal = 'withdrawal';
    case Transfer = 'transfer';
    case Commission = 'commission';
    case Fee = 'fee';
    case Payout = 'payout';
    case Settlement = 'settlement';
    case EscrowHold = 'escrow_hold';
    case EscrowRelease = 'escrow_release';
}
