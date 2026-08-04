<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Enum;

/**
 * Double-entry ledger accounts. Every financial movement posts balanced debit
 * and credit entries across these accounts, giving a tamper-evident audit trail
 * and tax-ready reporting.
 */
enum LedgerAccount: string
{
    case Cash = 'cash';                 // funds received from a provider
    case CustomerWallet = 'customer_wallet';
    case VendorWallet = 'vendor_wallet';
    case DriverWallet = 'driver_wallet';
    case Escrow = 'escrow';             // held platform funds pending release
    case Commission = 'commission';     // platform commission revenue
    case Fees = 'fees';                 // processing fees
    case Payouts = 'payouts';           // funds paid out to vendors/drivers
    case Refunds = 'refunds';           // funds refunded to customers
}
