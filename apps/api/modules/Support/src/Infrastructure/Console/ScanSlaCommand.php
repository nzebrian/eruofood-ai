<?php

declare(strict_types=1);

namespace EruoFood\Support\Infrastructure\Console;

use EruoFood\Support\Application\Service\SlaService;
use Illuminate\Console\Command;

/**
 * Scans for tickets that have breached their resolution SLA, publishing a
 * breach event and (when configured) escalating each. Run on a schedule
 * (e.g. every few minutes) so SLA monitoring is continuous.
 */
final class ScanSlaCommand extends Command
{
    protected $signature = 'support:sla-scan {--limit=100 : Maximum tickets to process}';

    protected $description = 'Detect and act on support tickets past their resolution SLA.';

    public function handle(SlaService $sla): int
    {
        $handled = $sla->scanBreaches((int) $this->option('limit'));
        $this->info(sprintf('Processed %d SLA breach(es).', $handled));

        return self::SUCCESS;
    }
}
