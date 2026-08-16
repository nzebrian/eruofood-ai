<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Infrastructure\Console;

use EruoFood\Dispatch\Application\Service\StaleRiderSweepService;
use Illuminate\Console\Command;

/**
 * Release deliveries held by riders whose phones have gone dark.
 *
 * Runs in `--report-only` mode by default. That is not caution for its own
 * sake: the first time this is pointed at production, an operator needs to
 * compare what it *would* release against the live board, because a threshold
 * that is slightly too aggressive takes deliveries away from riders who are
 * merely in a lift.
 *
 * Acting requires both the explicit `--apply` flag and the
 * `dispatch.stale_rider_sweep` feature flag. Two switches rather than one,
 * because this is the sweep that can move a customer's dinner to a different
 * rider without anybody watching.
 */
final class SweepStaleRidersCommand extends Command
{
    protected $signature = 'dispatch:sweep-stale-riders {--apply : Actually release; without this the command only reports}';

    protected $description = 'Find riders whose location heartbeat has stopped and release the deliveries they are holding.';

    public function handle(StaleRiderSweepService $sweep): int
    {
        $apply = (bool) $this->option('apply');
        $result = $sweep->sweep(reportOnly: ! $apply);

        $this->line($apply ? '<comment>Applying.</comment>' : '<info>Report only — nothing was changed.</info>');
        $this->line(sprintf('  active assignments examined : %d', $result['examined']));
        $this->line(sprintf('  reassigned                  : %d', $result['assignments_reassigned']));
        $this->line(sprintf('  held past pickup            : %d', $result['held_past_pickup']));

        if ($result['held_past_pickup'] > 0) {
            // Not a failure, but the one outcome a person has to act on: a
            // rider is carrying somebody's food and is not answering.
            $this->warn(sprintf(
                '  %d rider(s) hold food and have gone dark. Reassignment cannot take that back — operations must intervene.',
                $result['held_past_pickup'],
            ));
        }

        return self::SUCCESS;
    }
}
