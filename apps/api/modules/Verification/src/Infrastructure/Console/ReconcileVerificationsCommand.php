<?php

declare(strict_types=1);

namespace EruoFood\Verification\Infrastructure\Console;

use EruoFood\Verification\Application\Service\ReconciliationService;
use Illuminate\Console\Command;

/**
 * Catches verifications a lost webhook would strand, and expires lapsed ones.
 *
 * Meant for the scheduler. Exits non-zero only on a genuine failure to reach a
 * provider, so it can be alerted on without firing every time a case is simply
 * still pending.
 */
final class ReconcileVerificationsCommand extends Command
{
    protected $signature = 'verification:reconcile {--limit=100 : Maximum stalled cases to poll} {--skip-expiry : Only reconcile, do not sweep expiries}';

    protected $description = 'Poll providers for stalled verification cases and expire lapsed verifications';

    public function handle(ReconciliationService $reconciliation): int
    {
        $result = $reconciliation->reconcileStalled((int) $this->option('limit'));

        $this->line(sprintf(
            'Stalled cases — checked: %d, updated: %d, unreachable: %d',
            $result['checked'],
            $result['updated'],
            $result['failed'],
        ));

        if (! $this->option('skip-expiry')) {
            $expiry = $reconciliation->expireLapsed();
            $this->line(sprintf('Lapsed verifications expired: %d', $expiry['expired']));
        }

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
