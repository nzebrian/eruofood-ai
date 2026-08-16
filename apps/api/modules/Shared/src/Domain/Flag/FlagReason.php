<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\Flag;

/**
 * The rule that decided a flag, in evaluation order.
 *
 * The order matters and is asserted by tests: a kill switch that could be
 * overridden by a percentage rollout would not be a kill switch.
 */
enum FlagReason: string
{
    /** The environment override said so. The fastest lever in an incident. */
    case EnvironmentOverride = 'environment_override';

    /** Explicitly named — this merchant, this country, this region. */
    case TargetedMatch = 'targeted_match';

    /** In the rolled-out percentage for this flag. */
    case PercentageRollout = 'percentage_rollout';

    /** A rollout exists, and this subject is outside it. */
    case OutsideRollout = 'outside_rollout';

    /** Nothing matched; the flag's declared safe default applied. */
    case SafeDefault = 'safe_default';

    /**
     * The flag store could not be read, so the safe default applied.
     *
     * Distinct from SafeDefault on purpose: this one means something is broken
     * and should be alerted on, even though the platform behaved correctly.
     */
    case StoreUnavailable = 'store_unavailable';

    public function explain(): string
    {
        return match ($this) {
            self::EnvironmentOverride => 'an environment override is set',
            self::TargetedMatch => 'the subject is explicitly targeted',
            self::PercentageRollout => 'the subject falls inside the rollout percentage',
            self::OutsideRollout => 'the subject falls outside the rollout',
            self::SafeDefault => 'no rule matched, so the safe default applied',
            self::StoreUnavailable => 'the flag store could not be read, so the safe default applied',
        };
    }

    /** Whether this outcome indicates a problem worth alerting on. */
    public function isDegraded(): bool
    {
        return $this === self::StoreUnavailable;
    }
}
