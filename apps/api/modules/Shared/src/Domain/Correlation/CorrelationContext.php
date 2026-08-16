<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\Correlation;

/**
 * The correlation id for whatever this process is currently doing.
 *
 * ## Why ambient state, when everything else here is injected
 *
 * Passing a correlation id explicitly through eight contexts would mean adding
 * a parameter to every method between the controller and the ledger — including
 * domain methods that have no business knowing about tracing. The alternative
 * to ambient state here is worse than ambient state.
 *
 * It is kept deliberately small: set at the edge, read by infrastructure,
 * invisible to the domain. Nothing in `Domain/` outside this namespace should
 * reference it.
 *
 * ## Absence is normal
 *
 * A console command, a queued job replayed after a deploy, or a test may have
 * no correlation. Callers get a generated id rather than null, so no call site
 * needs a null branch and no log line is missing the field.
 */
final class CorrelationContext
{
    private static ?CorrelationId $current = null;

    public static function set(CorrelationId $id): void
    {
        self::$current = $id;
    }

    /** The current correlation, generating one if this process has none yet. */
    public static function current(): CorrelationId
    {
        return self::$current ??= CorrelationId::generate();
    }

    /** Whether a correlation has actually been established, without creating one. */
    public static function has(): bool
    {
        return self::$current !== null;
    }

    /** The value safe for audit records and the ledger — always server-generated. */
    public static function forAudit(): string
    {
        return self::current()->forAudit();
    }

    /**
     * Forget the current correlation.
     *
     * Queue workers are long-lived processes handling one job after another. A
     * worker that does not clear this would stamp job #2 with job #1's id, which
     * is worse than having no id at all: it asserts a relationship that does not
     * exist. The queue listener clears it after every job, and tests reset it
     * between cases.
     */
    public static function clear(): void
    {
        self::$current = null;
    }
}
