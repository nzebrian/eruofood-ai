<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\Schedule;

use EruoFood\Shared\Domain\Exception\InvalidArgumentException;

/**
 * One piece of recurring background work, described rather than scheduled.
 *
 * ## Why a description and not a Laravel schedule call
 *
 * Recurring work belongs to the context that owns the data — offer expiry is
 * Dispatch's, retention purges are Verification's. But `bootstrap/app.php` is
 * where Laravel wants the schedule defined, and a bootstrap file that names
 * every module's internals is exactly the coupling this codebase avoids
 * everywhere else.
 *
 * So a module *describes* its recurring work and registers the description with
 * {@see ScheduleRegistry}. The bootstrap drains the registry without knowing
 * what is in it. A new scheduled task is a change in one module, not two.
 *
 * ## Every task is off unless something turns it on
 *
 * `enabled` is required, not defaulted. Recurring work runs unattended against
 * production data, and the failure mode of an accidentally-enabled sweep is a
 * job quietly changing rows nobody asked it to change. Making the flag explicit
 * means the decision is visible in the module that registered it.
 */
final readonly class ScheduledTask
{
    private function __construct(
        public string $name,
        public string $command,
        public Cadence $cadence,
        public bool $enabled,
        public bool $withoutOverlapping,
        public string $description,
    ) {
    }

    /**
     * @param string $name stable identifier, used for overlap locks and logs
     * @param string $command the artisan command to run
     * @param bool $enabled whether this task should actually be scheduled
     */
    public static function of(
        string $name,
        string $command,
        Cadence $cadence,
        bool $enabled,
        string $description,
        bool $withoutOverlapping = true,
    ): self {
        if (trim($name) === '') {
            throw new InvalidArgumentException('A scheduled task needs a name.');
        }

        if (trim($command) === '') {
            throw new InvalidArgumentException("Scheduled task '{$name}' needs a command.");
        }

        if (trim($description) === '') {
            // Not bureaucracy. An operator looking at a task list at 3am needs
            // to know what a name like `dispatch:sweep-stale-riders` will do
            // before deciding whether to disable it.
            throw new InvalidArgumentException("Scheduled task '{$name}' needs a description.");
        }

        return new self($name, $command, $cadence, $enabled, $withoutOverlapping, $description);
    }
}
