<?php

declare(strict_types=1);

namespace EruoFood\Payments\Infrastructure\Console;

use EruoFood\Payments\Application\Service\SettlementReconciliationService;
use Illuminate\Console\Command;

/**
 * Run the four reconcilers once.
 *
 * ## It changes nothing on its own
 *
 * Every reconciler is read-only except where the provider gives a definitive
 * answer about a transfer we already made. It opens cases; it does not close
 * ones it cannot prove, and it moves no money that was not already moved.
 *
 * ## It is gated twice
 *
 * The scheduled task that names this command is registered **disabled**, and
 * every method it calls checks `settlement.reconcile` for itself. Running the
 * command by hand with the flag off therefore does nothing and says so, rather
 * than appearing to work.
 */
final class ReconcileSettlementsCommand extends Command
{
    protected $signature = 'payments:reconcile-settlements {--limit=50 : How many unresolved payout attempts to examine}';

    protected $description = 'Compare provider, ledger, payable and payment records, and open a case for each disagreement.';

    public function handle(SettlementReconciliationService $reconciliation): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $payouts = $reconciliation->reconcilePayouts($limit);
        $payableDrift = $reconciliation->reconcileLedgerAgainstPayable();
        $ledgerDrift = $reconciliation->reconcileLedgerAgainstWallets();
        $orphans = $reconciliation->reconcilePaymentsAgainstAccruals($limit);

        $this->table(
            ['check', 'result'],
            [
                ['payout attempts examined', (string) $payouts['examined']],
                ['confirmed by provider', (string) $payouts['confirmed']],
                ['confirmed as never sent', (string) $payouts['failed']],
                ['still unknown', (string) $payouts['still_unknown']],
                ['cases opened (payout)', (string) $payouts['cases_opened']],
                ['payable drift', $payableDrift === null ? 'none' : 'case '.$payableDrift->id()],
                ['ledger imbalance', $ledgerDrift === null ? 'none' : 'case '.$ledgerDrift->id()],
                ['orphan accruals', (string) count($orphans)],
            ],
        );

        // Always success. A discrepancy is a finding, not a command failure —
        // a non-zero exit would make the scheduler treat "we found something"
        // as "the job is broken", and the alerting would be pointed at the
        // wrong thing.
        return self::SUCCESS;
    }
}
