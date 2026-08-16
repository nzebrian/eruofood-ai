<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\Time;

/**
 * What a stored temporal value actually means, for the purposes of the UTC
 * cutover.
 *
 * ## Why the SQL type is not enough
 *
 * Every column in scope is `timestamp without time zone`, and the first version
 * of the backfill treated that as the whole answer: 262 columns discovered, 262
 * columns shifted. That is wrong, and wrong in a way that corrupts data rather
 * than failing loudly.
 *
 * A `timestamp` column holds whatever was written to it. Most of this platform
 * writes instants from the application clock — those really were Lagos
 * wall-clock and really must move. But some columns hold a *date* a person
 * typed, parsed at midnight; some hold a business period boundary; and some
 * hold a value a provider sent us that was already UTC. Shifting a rider's
 * insurance expiry of `2027-01-15 00:00:00` by minus one hour moves it to
 * `2027-01-14` — the document now expires a day early, silently, for every
 * rider on the platform.
 *
 * So provenance decides, not type. Only {@see self::ConvertibleInstant} is
 * converted; everything else is excluded and says why.
 */
enum BackfillCategory: string
{
    /**
     * A. An instant written from the application clock, in Lagos wall-clock.
     *
     * The only category the migration converts. These are the `created_at`,
     * `updated_at`, `*_at` event stamps and clock-derived deadlines that the
     * platform itself produced with `now()`.
     */
    case ConvertibleInstant = 'A';

    /**
     * B. An instant that was already UTC when it was stored.
     *
     * Typically parsed from a provider payload carrying an explicit offset
     * (`...Z`, `+00:00`). PHP builds those with the offset from the string, not
     * the default timezone, so they were never Lagos and shifting them would
     * introduce the error the cutover exists to remove.
     */
    case AlreadyUtc = 'B';

    /**
     * C. A calendar date, stored in a timestamp column at midnight.
     *
     * A date is not an instant. `2027-01-15` means that day wherever you are;
     * subtracting an hour moves it to the previous day and changes its meaning.
     */
    case DateOnly = 'C';

    /**
     * D. A duration, interval or count — temporal but not a point in time.
     *
     * Nothing to shift: an offset applied to a length is simply an error. No
     * column in the current schema falls here (durations are stored as integer
     * seconds), and the category exists so that one added later is classified
     * rather than swept in.
     */
    case Duration = 'D';

    /**
     * E. Framework or system bookkeeping.
     *
     * The migration's own audit log and Laravel's internals. Excluded because
     * they describe the change rather than participate in it — and because the
     * log is written with the database clock precisely so it stays readable
     * across the cutover.
     */
    case SystemMetadata = 'E';

    /**
     * F. Mixed or unverified provenance.
     *
     * The important one. A column lands here when its rows did not all come
     * from the same place — a provider date when one was supplied, a
     * clock-derived fallback when it was not — so no single shift is correct
     * for the whole column. It is also the default for anything not positively
     * classified, because the safe failure mode for a one-way data rewrite is
     * to leave a column alone and report it, not to guess.
     */
    case UnverifiedOrMixed = 'F';

    public function isConverted(): bool
    {
        return $this === self::ConvertibleInstant;
    }

    /** Why this category is or is not converted — printed in the manifest. */
    public function explain(): string
    {
        return match ($this) {
            self::ConvertibleInstant => 'Application instant written from the server clock in Africa/Lagos wall-clock. Converted.',
            self::AlreadyUtc => 'Already stored as UTC (parsed from a value carrying an explicit offset). Shifting would introduce an error.',
            self::DateOnly => 'A calendar date held at midnight. Shifting moves it to the previous day and changes its meaning.',
            self::Duration => 'A length of time rather than a point in time. An offset does not apply.',
            self::SystemMetadata => 'Framework or migration bookkeeping, deliberately outside the cutover.',
            self::UnverifiedOrMixed => 'Provenance is mixed or unverified, so no single shift is correct for every row. Excluded pending manual classification.',
        };
    }
}
