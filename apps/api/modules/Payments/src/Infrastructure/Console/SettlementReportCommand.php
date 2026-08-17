<?php

declare(strict_types=1);

namespace EruoFood\Payments\Infrastructure\Console;

use EruoFood\Payments\Domain\Settlement\PayableAccrualRepository;
use EruoFood\Payments\Domain\Settlement\PayoutAttemptRepository;
use EruoFood\Payments\Domain\Settlement\SettlementRunRepository;
use Illuminate\Console\Command;

/**
 * The report-only cycle's output.
 *
 * This is the command finance runs during the accrual cycle, before any money
 * moves, to compare the platform's totals against the figures they produce by
 * hand. It reads and prints; there is no flag it can be given that makes it
 * write anything.
 *
 * `reporting_only` in the output is the number that matters during that cycle:
 * while `settlement.accrual_posting` is off, every accrual is report-only and
 * none of them can be settled. When that count stops growing, the posting flag
 * has been turned on.
 */
final class SettlementReportCommand extends Command
{
    protected $signature = 'payments:settlement-report';

    protected $description = 'Print accrual, settlement-run and payout-attempt totals for the report-only cycle.';

    public function handle(
        PayableAccrualRepository $accruals,
        SettlementRunRepository $runs,
        PayoutAttemptRepository $attempts,
    ): int {
        $totals = $accruals->totals();

        $this->info('Accruals');
        $this->table(['metric', 'value'], [
            ['rows', (string) $totals['count']],
            ['earnings', (string) $totals['earnings']],
            ['refund adjustments', (string) $totals['adjustments']],
            ['gross (minor)', (string) $totals['gross_minor']],
            ['commission (minor)', (string) $totals['commission_minor']],
            ['fees (minor)', (string) $totals['fee_minor']],
            ['net (minor)', (string) $totals['net_minor']],
            ['report-only (not settleable)', (string) $totals['reporting_only']],
        ]);

        $this->info('Settlement runs by state');
        $this->table(['state', 'count'], $this->rows($runs->countsByState()));

        $this->info('Payout attempts by state');
        $this->table(['state', 'count'], $this->rows($attempts->countsByState()));

        $this->line(sprintf('Paid out of MerchantPayable: %d (minor units)', $runs->paidOutNetMinor()));
        $this->line(sprintf('Posted accrual net: %d (minor units)', $accruals->postedNetMinor()));

        return self::SUCCESS;
    }

    /**
     * @param array<string, int> $counts
     * @return list<array{0: string, 1: string}>
     */
    private function rows(array $counts): array
    {
        if ($counts === []) {
            return [['(none)', '0']];
        }

        $rows = [];
        foreach ($counts as $state => $count) {
            $rows[] = [$state, (string) $count];
        }

        return $rows;
    }
}
