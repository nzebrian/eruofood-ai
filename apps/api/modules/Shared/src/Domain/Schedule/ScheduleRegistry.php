<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\Schedule;

use EruoFood\Shared\Domain\Exception\InvalidArgumentException;

/**
 * Where modules put their recurring work, and where the bootstrap collects it.
 *
 * Registered during service-provider registration, drained once when Laravel
 * builds its schedule. A singleton in the container, so registration order
 * across modules does not matter.
 *
 * Duplicate names are rejected rather than merged: two tasks sharing a name
 * would share an overlap lock, and the second would silently never run.
 */
final class ScheduleRegistry
{
    /** @var array<string, ScheduledTask> */
    private array $tasks = [];

    public function register(ScheduledTask $task): void
    {
        if (isset($this->tasks[$task->name])) {
            throw new InvalidArgumentException(
                "A scheduled task named '{$task->name}' is already registered.",
            );
        }

        $this->tasks[$task->name] = $task;
    }

    /**
     * Everything registered, including tasks that are switched off.
     *
     * Disabled tasks are returned rather than filtered out so an operator
     * command can show what *exists* alongside what is running — a task missing
     * from the list because it is disabled looks identical to one nobody ever
     * wrote.
     *
     * @return list<ScheduledTask>
     */
    public function all(): array
    {
        return array_values($this->tasks);
    }

    /** @return list<ScheduledTask> */
    public function enabled(): array
    {
        return array_values(array_filter($this->tasks, static fn (ScheduledTask $t): bool => $t->enabled));
    }
}
