<?php

declare(strict_types=1);

namespace EruoFood\Payments\Infrastructure\Console;

use EruoFood\Payments\Application\Service\LedgerIntegrityService;
use Illuminate\Console\Command;

/**
 * Reconciliation check for the financial ledger.
 *
 * Exits non-zero when the book does not balance so it can be wired to an alert:
 * an unbalanced ledger is a finance incident, not a log line.
 */
final class VerifyLedgerCommand extends Command
{
    protected $signature = 'payments:verify-ledger {--json : Emit the report as JSON}';

    protected $description = 'Verify that the double-entry ledger balances, globally and per financial event';

    public function handle(LedgerIntegrityService $integrity): int
    {
        $report = $integrity->verify();

        if ($this->option('json')) {
            $this->line((string) json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return $report->isBalanced() ? self::SUCCESS : self::FAILURE;
        }

        $this->line(sprintf('Financial events checked: %d', $report->correlationsChecked));
        $this->line(sprintf('Net balance (minor units): %d', $report->netMinor));

        if ($report->isBalanced()) {
            $this->info('Ledger balances.');

            return self::SUCCESS;
        }

        $this->error('LEDGER DOES NOT BALANCE.');
        foreach ($report->unbalancedCorrelationIds as $id) {
            $this->error(sprintf('  unbalanced financial event: %s', $id));
        }

        return self::FAILURE;
    }
}
