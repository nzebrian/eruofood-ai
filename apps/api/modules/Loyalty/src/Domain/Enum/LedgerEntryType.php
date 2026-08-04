<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Domain\Enum;

/**
 * The kind of a points-ledger entry. Earn/adjust-up add to the balance;
 * redeem/expire/adjust-down subtract. The ledger is append-only — a balance is
 * always the signed sum of its entries, never a mutated field.
 */
enum LedgerEntryType: string
{
    case Earn = 'earn';       // points awarded (order, review, referral, signup)
    case Redeem = 'redeem';   // points spent on a reward
    case Expire = 'expire';   // points swept by the expiry policy
    case Adjust = 'adjust';   // a manual admin correction (may be + or -)

    /** Whether an entry of this type counts toward lifetime-earned points (for tiers). */
    public function countsToLifetime(): bool
    {
        return $this === self::Earn;
    }
}
