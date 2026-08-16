<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\Time;

/**
 * What happened when a local wall-clock time was resolved to a real instant.
 *
 * Callers that only want the instant can ignore this. Callers that schedule
 * things people will notice — a merchant's opening time, a reminder — can use
 * it to say "the clocks changed, so this ran at 02:00 rather than 01:30"
 * instead of leaving an operator to wonder.
 */
enum LocalResolution: string
{
    /** The ordinary case: exactly one instant matches. */
    case Unique = 'unique';

    /** The time did not exist (clocks sprang forward); moved to the first valid instant. */
    case Gap = 'gap';

    /** The time happened twice (clocks fell back); the earlier instant was taken. */
    case Overlap = 'overlap';

    public function wasAdjusted(): bool
    {
        return $this !== self::Unique;
    }

    /** Operator-facing wording, for logs and dashboards. */
    public function explain(): string
    {
        return match ($this) {
            self::Unique => 'The local time maps to a single instant.',
            self::Gap => 'The local time does not exist on this date because the clocks moved forward; the first valid instant was used.',
            self::Overlap => 'The local time occurs twice on this date because the clocks moved back; the earlier instant was used.',
        };
    }
}
