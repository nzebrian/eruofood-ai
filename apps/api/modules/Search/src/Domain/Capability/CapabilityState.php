<?php

declare(strict_types=1);

namespace EruoFood\Search\Domain\Capability;

/**
 * What is actually known about a database capability (M38-DB-001, M38-VECTOR-001).
 *
 * Four states, because there really are four. Before M38 the migration created
 * pgvector and pg_trgm inside `try { … } catch (\Throwable) { }` and carried on
 * as though the acceleration existed. A missing extension, a permissions error
 * and a healthy install were indistinguishable afterwards — and no Postgres
 * image in this repository actually ships pgvector, so the most likely real
 * state was "silently absent everywhere".
 *
 * `Unavailable` and `ProbeFailed` are deliberately distinct. "The extension is
 * not installed" is a fact; "we could not find out" is not, and rounding the
 * second down to the first is how a broken probe starts reporting healthy.
 */
enum CapabilityState: string
{
    /** Verified present against the live connection. */
    case Available = 'available';

    /** Verified absent. The documented degraded path applies. */
    case Unavailable = 'unavailable';

    /** The probe itself failed. Nothing is known — never treat as healthy. */
    case ProbeFailed = 'probe_failed';

    /** Switched off by configuration. Not a fault. */
    case DisabledByConfig = 'disabled_by_config';

    /** Whether the capability may actually be used for querying. */
    public function isUsable(): bool
    {
        return $this === self::Available;
    }

    /** Whether this state represents a fault an operator should see. */
    public function isDegraded(): bool
    {
        return $this === self::Unavailable || $this === self::ProbeFailed;
    }

    public function describe(): string
    {
        return match ($this) {
            self::Available => 'available and in use',
            self::Unavailable => 'not installed — running the documented fallback',
            self::ProbeFailed => 'could not be determined — assuming unusable',
            self::DisabledByConfig => 'disabled by configuration',
        };
    }
}
