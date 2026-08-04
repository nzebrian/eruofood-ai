<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Infrastructure\Console;

use EruoFood\Loyalty\Application\Service\LoyaltyService;
use Illuminate\Console\Command;

/**
 * Sweeps points that have passed their expiry off members' balances, publishing
 * an expiry event per account. Run on a schedule (e.g. nightly) so expiry is
 * applied continuously rather than at read time.
 */
final class ScanExpiryCommand extends Command
{
    protected $signature = 'loyalty:scan-expiry {--limit=500 : Maximum expired earn entries to process}';

    protected $description = 'Expire points past their expiry window and adjust member balances.';

    public function handle(LoyaltyService $loyalty): int
    {
        $touched = $loyalty->runExpiry((int) $this->option('limit'));
        $this->info(sprintf('Expired points across %d account(s).', $touched));

        return self::SUCCESS;
    }
}
