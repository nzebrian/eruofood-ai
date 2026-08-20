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

    /*
     * Money the platform has stopped merely holding and now *owes a named
     * merchant*.
     *
     * `Escrow` conflated the two. A single escrow balance answers "how much of
     * the money in our account is not ours", which is a solvency question, and
     * cannot answer "what do we owe this merchant", which is the settlement
     * question. Deriving the second from the first meant guessing, and guessing
     * is why a settlement amount used to arrive in a request body.
     *
     * Funds move Escrow → MerchantPayable when an order becomes financially
     * final, and MerchantPayable → Payouts when a settlement pays out. The
     * balance of this account must always equal the sum of unsettled accruals;
     * a reconciler asserts exactly that.
     */
    case MerchantPayable = 'merchant_payable';

    case Commission = 'commission';     // platform commission revenue
    case Fees = 'fees';                 // processing fees
    case Payouts = 'payouts';           // funds paid out to vendors/drivers
    case Refunds = 'refunds';           // funds refunded to customers
}
