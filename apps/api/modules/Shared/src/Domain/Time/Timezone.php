<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\Time;

use DateTimeImmutable;
use DateTimeZone;
use EruoFood\Shared\Domain\Exception\InvalidArgumentException;

/**
 * An IANA timezone identifier — the only kind of timezone this platform stores.
 *
 * ## Why a value object rather than a string column
 *
 * A timezone is not free text and it is not a UTC offset. `Africa/Lagos` is a
 * *rule set*: it says what the offset is on any given date, including whether
 * daylight saving applies. `+01:00` is a single number that happens to be
 * correct today. Storing the offset is the classic mistake — it silently
 * produces a one-hour error twice a year in every zone that observes DST, and
 * the error appears in the past as soon as a government changes the rules.
 *
 * So this type accepts only identifiers that appear in the tz database, and
 * rejects the things people reach for instead: raw offsets (`+01:00`), the
 * `GMT+1` forms PHP will happily parse, and empty strings.
 *
 * ## What it deliberately does not do
 *
 * It does not carry an instant. A timezone answers "what does wall-clock time
 * mean *here*"; it is meaningless without a moment to apply it to, which is why
 * every method that returns an offset demands one. See {@see WallClock} for
 * converting between an authoritative UTC instant and local wall-clock time.
 */
final readonly class Timezone
{
    private function __construct(public string $identifier)
    {
    }

    /**
     * @throws InvalidArgumentException when the value is not an IANA identifier
     */
    public static function of(string $identifier): self
    {
        $trimmed = trim($identifier);

        if ($trimmed === '') {
            throw new InvalidArgumentException('A timezone identifier is required.');
        }

        // The membership test is the whole point. `new DateTimeZone('+01:00')`
        // and `new DateTimeZone('GMT+1')` both succeed, and both are exactly
        // what this type exists to keep out of the database.
        if (! in_array($trimmed, self::identifiers(), true)) {
            throw new InvalidArgumentException(
                "'{$trimmed}' is not an IANA timezone identifier (expected a value such as 'Africa/Lagos' or 'UTC').",
            );
        }

        return new self($trimmed);
    }

    /** The same thing as of(), but null in and null out — for optional columns. */
    public static function ofNullable(?string $identifier): ?self
    {
        return $identifier === null || trim($identifier) === '' ? null : self::of($identifier);
    }

    /** The authoritative zone. Every stored timestamp is in this one. */
    public static function utc(): self
    {
        return new self('UTC');
    }

    public function isUtc(): bool
    {
        return $this->identifier === 'UTC';
    }

    public function toDateTimeZone(): DateTimeZone
    {
        return new DateTimeZone($this->identifier);
    }

    /**
     * The offset from UTC, in seconds, *at a given instant*.
     *
     * The parameter is not optional and not defaulted. An offset without a
     * moment is the bug this class was written to prevent.
     */
    public function offsetSecondsAt(DateTimeImmutable $instant): int
    {
        return $this->toDateTimeZone()->getOffset($instant);
    }

    /** Whether daylight saving is in force here at the given instant. */
    public function isDaylightSavingAt(DateTimeImmutable $instant): bool
    {
        $local = $instant->setTimezone($this->toDateTimeZone());

        return $local->format('I') === '1';
    }

    public function equals(self $other): bool
    {
        return $this->identifier === $other->identifier;
    }

    public function __toString(): string
    {
        return $this->identifier;
    }

    /**
     * The tz database, as PHP sees it.
     *
     * `listIdentifiers()` without arguments returns the canonical set and
     * excludes backward-compatibility aliases, which is what we want: one
     * spelling per zone, so two rows for the same place compare equal.
     *
     * @return list<string>
     */
    private static function identifiers(): array
    {
        /** @var list<string>|null $cached */
        static $cached = null;

        return $cached ??= DateTimeZone::listIdentifiers();
    }
}
