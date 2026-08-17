<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Enum;

/** What kind of disagreement a {@see \EruoFood\Payments\Domain\Settlement\ReconciliationCase} records. */
enum DiscrepancyKind: string
{
    /** The provider's view of a transfer differs from ours, or cannot be got. */
    case PayoutStateMismatch = 'payout_state_mismatch';

    /** The ledger's account balances and the wallet balances disagree. */
    case LedgerWalletDrift = 'ledger_wallet_drift';

    /** The derived payable and the MerchantPayable ledger balance disagree. */
    case PayableDrift = 'payable_drift';

    /** A confirmed, delivered order that never produced an accrual. */
    case MissingAccrual = 'missing_accrual';

    /** An accrual with no confirmed payment behind it. */
    case OrphanAccrual = 'orphan_accrual';

    /**
     * Whether a case of this kind may ever be closed automatically.
     *
     * Only a payout state mismatch can: the provider is asked again, and if it
     * now agrees with us the disagreement was a transient outage. Every other
     * kind is a disagreement between two things the platform itself wrote, and
     * a system that silently reconciles its own contradictions is a system that
     * hides them.
     */
    public function isAutoResolvable(): bool
    {
        return $this === self::PayoutStateMismatch;
    }
}
