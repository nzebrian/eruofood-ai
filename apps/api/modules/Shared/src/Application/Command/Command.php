<?php

declare(strict_types=1);

namespace EruoFood\Shared\Application\Command;

/**
 * Marker interface for Commands (write intents).
 *
 * Commands are immutable DTOs describing an intent to change state. They are
 * handled by a single command handler in the application layer, keeping
 * controllers thin (CQRS-lite — MASTER_PLAN.md §5.2).
 */
interface Command
{
}
