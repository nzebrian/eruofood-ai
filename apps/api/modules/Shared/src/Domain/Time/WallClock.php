<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\Time;

use DateTimeImmutable;
use DateTimeZone;
use EruoFood\Shared\Domain\Exception\InvalidArgumentException;

/**
 * Converting between an authoritative UTC instant and somebody's local clock.
 *
 * ## The two directions are not symmetrical
 *
 * Going *from* an instant *to* wall-clock time always works: every moment has
 * exactly one local representation. That is {@see self::localise()}, and it is
 * the boring direction.
 *
 * Going the other way — "09:00 on the 29th of March, in Europe/London" — can
 * fail in two ways that most code never handles:
 *
 * - **The gap.** When the clocks spring forward, an hour does not exist. In
 *   London in 2026, 01:00–02:00 on 29 March is simply absent. A merchant who
 *   sets opening hours of 01:30 has named a time that never happens.
 * - **The overlap.** When the clocks fall back, an hour happens twice. 01:30 on
 *   25 October 2026 occurs at both +01:00 and +00:00, an hour apart. A
 *   notification scheduled for 01:30 has two candidate instants.
 *
 * PHP resolves both silently, and its answers are not the ones an operations
 * team expects. So this class resolves them explicitly, with a documented
 * policy, and can report which case it hit.
 *
 * ## The policy
 *
 * - **Gap** → move *forward* to the first instant that does exist. A shop that
 *   opens at 01:30 on a spring-forward morning opens when the clocks reach
 *   02:00. Moving backward would open it the previous evening.
 * - **Overlap** → take the *earlier* of the two instants. A 01:30 reminder
 *   fires on the first pass, not an hour later. Choosing the later one would
 *   look like a delayed notification to the person receiving it.
 *
 * Both choices favour "sooner, and only once" — which is what people mean by a
 * local time, and which never fires the same scheduled thing twice.
 *
 * Nigeria (`Africa/Lagos`, the platform's primary market) has no daylight
 * saving, so neither case arises there. They arise the moment EruoFood serves a
 * second market, which is exactly when nobody will be looking for them.
 */
final readonly class WallClock
{
    private const DATE_TIME = 'Y-m-d H:i:s';

    private function __construct(
        public DateTimeImmutable $instant,
        public LocalResolution $resolution,
    ) {
    }

    /**
     * An authoritative instant, expressed on the clock of a given place.
     *
     * The returned value is the same moment — only its presentation changes.
     */
    public static function localise(DateTimeImmutable $instant, Timezone $zone): DateTimeImmutable
    {
        return $instant->setTimezone($zone->toDateTimeZone());
    }

    /**
     * Resolve a local date and time to the authoritative UTC instant.
     *
     * @param string $date `Y-m-d` as written on a local calendar
     * @param string $time `H:i` or `H:i:s` as read on a local clock
     *
     * @throws InvalidArgumentException when the date or time is malformed
     */
    public static function resolve(string $date, string $time, Timezone $zone): self
    {
        $wanted = $date.' '.self::normaliseTime($time);
        $utc = new DateTimeZone('UTC');

        // Parsed as if it were UTC. This is not the answer — it is a way of
        // holding the requested wall-clock reading as a number so candidate
        // offsets can be subtracted from it.
        $naive = DateTimeImmutable::createFromFormat(self::DATE_TIME, $wanted, $utc);

        if ($naive === false || $naive->format(self::DATE_TIME) !== $wanted) {
            // Catches PHP's overflow habit: '2026-02-30' parses happily into
            // 2 March. A merchant's opening hours quietly landing on a
            // different day is worse than an error.
            throw new InvalidArgumentException("'{$date} {$time}' is not a valid local date and time.");
        }

        $zoneObject = $zone->toDateTimeZone();
        $matches = self::candidateInstants($naive, $wanted, $zoneObject);

        if ($matches === []) {
            return new self(self::firstInstantAfterGap($naive, $zoneObject), LocalResolution::Gap);
        }

        // Sorted so "the earlier instant" is a fact about the data rather than
        // a fact about the order the offsets happened to be discovered in.
        sort($matches);

        return new self(
            (new DateTimeImmutable('@'.$matches[0]))->setTimezone($utc),
            count($matches) > 1 ? LocalResolution::Overlap : LocalResolution::Unique,
        );
    }

    /**
     * Every instant whose local rendering is the requested wall-clock reading.
     *
     * Asks the zone's transition table which offsets are actually in force near
     * this date, subtracts each from the requested reading, and keeps the
     * candidates that render back to what was asked for. Zero matches means the
     * time does not exist; two means it happens twice.
     *
     * This replaces an earlier attempt that used `modify('-1 hour')` on a zoned
     * object. That is unreliable precisely where it matters: `modify()` works in
     * wall-clock terms, so across a transition it produces the wrong instant and
     * misclassified both DST cases as ordinary.
     *
     * @return list<int> matching UTC timestamps
     */
    private static function candidateInstants(DateTimeImmutable $naive, string $wanted, DateTimeZone $zone): array
    {
        $matches = [];

        foreach (self::nearbyOffsets($naive, $zone) as $offset) {
            $timestamp = $naive->getTimestamp() - $offset;
            $rendered = (new DateTimeImmutable('@'.$timestamp))->setTimezone($zone);

            if ($rendered->format(self::DATE_TIME) === $wanted && ! in_array($timestamp, $matches, true)) {
                $matches[] = $timestamp;
            }
        }

        return $matches;
    }

    /**
     * The UTC offsets in force around this date.
     *
     * A two-day window either side comfortably contains any transition that
     * could affect the requested time, while keeping the candidate set to the
     * two or three offsets that are genuinely plausible.
     *
     * @return list<int>
     */
    private static function nearbyOffsets(DateTimeImmutable $naive, DateTimeZone $zone): array
    {
        $from = $naive->getTimestamp() - 2 * 86400;
        $to = $naive->getTimestamp() + 2 * 86400;

        $offsets = [$zone->getOffset($naive)];

        /** @var list<array{ts: int, offset: int}>|false $transitions */
        $transitions = $zone->getTransitions($from, $to);

        if ($transitions !== false) {
            foreach ($transitions as $transition) {
                $offsets[] = $transition['offset'];
            }
        }

        return array_values(array_unique($offsets));
    }

    /**
     * The first instant that exists after a spring-forward gap.
     *
     * The gap is created at a transition: local time jumps straight from
     * `ts + oldOffset` to `ts + newOffset`, and the requested reading falls in
     * between. The first valid instant is therefore the transition itself —
     * 01:30 on a London spring-forward morning resolves to the moment the
     * clocks reach 02:00.
     *
     * Derived from the transition rather than by stepping forward a minute at a
     * time, so it is exact for zones whose shift is not a whole hour (Lord Howe
     * Island moves by thirty minutes).
     */
    private static function firstInstantAfterGap(DateTimeImmutable $naive, DateTimeZone $zone): DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');
        $from = $naive->getTimestamp() - 2 * 86400;
        $to = $naive->getTimestamp() + 2 * 86400;

        /** @var list<array{ts: int, offset: int}>|false $transitions */
        $transitions = $zone->getTransitions($from, $to);

        if ($transitions !== false) {
            foreach ($transitions as $transition) {
                // The transition that skipped past the requested reading: after
                // it, local time is already at or beyond what was asked for.
                if ($transition['ts'] + $transition['offset'] >= $naive->getTimestamp()) {
                    return (new DateTimeImmutable('@'.$transition['ts']))->setTimezone($utc);
                }
            }
        }

        // No transition explains the gap — not reachable for a real zone. The
        // normalised instant is still a correct moment, just not the one the
        // documented policy would have chosen.
        return $naive->setTimezone($utc);
    }

    private static function normaliseTime(string $time): string
    {
        $trimmed = trim($time);

        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $trimmed) === 1) {
            return $trimmed.':00';
        }

        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d:[0-5]\d$/', $trimmed) === 1) {
            return $trimmed;
        }

        throw new InvalidArgumentException("'{$time}' is not a valid local time (expected HH:MM or HH:MM:SS).");
    }
}
