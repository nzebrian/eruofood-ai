<?php

declare(strict_types=1);

use EruoFood\Shared\Domain\Exception\InvalidArgumentException;
use EruoFood\Shared\Domain\Time\LocalResolution;
use EruoFood\Shared\Domain\Time\Timezone;
use EruoFood\Shared\Domain\Time\WallClock;
use EruoFood\Shared\Infrastructure\Clock\SystemClock;

// ------------------------------------------------------- the storage contract

it('returns UTC from the authoritative clock even when PHP is set to another zone', function (): void {
    // The guarantee has to be structural, not configuration-dependent — a
    // differently-configured worker container must not write different values.
    // This is what `new DateTimeImmutable('now')` used to get wrong.
    date_default_timezone_set('Africa/Lagos');

    try {
        expect((new SystemClock())->now()->getTimezone()->getName())->toBe('UTC');
    } finally {
        date_default_timezone_set('UTC');
    }
});

// ------------------------------------------------------ IANA identifiers only

it('accepts IANA identifiers', function (string $id): void {
    expect(Timezone::of($id)->identifier)->toBe($id);
})->with(['UTC', 'Africa/Lagos', 'Europe/London', 'Australia/Lord_Howe']);

it('refuses anything that is not an IANA identifier', function (string $bad): void {
    // PHP's DateTimeZone accepts every one of these. Storing an offset is the
    // classic mistake: it is right until the day the rules change.
    expect(fn () => Timezone::of($bad))->toThrow(InvalidArgumentException::class, 'IANA');
})->with(['+01:00', 'GMT+1', 'WAT', 'Mars/Olympus']);

it('refuses an empty timezone outright', function (): void {
    expect(fn () => Timezone::of('  '))->toThrow(InvalidArgumentException::class, 'required');
});

it('reports the offset only against a specific instant', function (): void {
    $london = Timezone::of('Europe/London');

    $winter = new DateTimeImmutable('2026-01-15T12:00:00Z');
    $summer = new DateTimeImmutable('2026-07-15T12:00:00Z');

    expect($london->offsetSecondsAt($winter))->toBe(0)
        ->and($london->offsetSecondsAt($summer))->toBe(3600)
        ->and($london->isDaylightSavingAt($summer))->toBeTrue()
        ->and($london->isDaylightSavingAt($winter))->toBeFalse();
});

it('knows Lagos never observes daylight saving, which is what makes the backfill exact', function (): void {
    $lagos = Timezone::of('Africa/Lagos');

    foreach (['2026-01-01', '2026-03-29', '2026-07-01', '2026-10-25'] as $date) {
        expect($lagos->offsetSecondsAt(new DateTimeImmutable("{$date}T12:00:00Z")))->toBe(3600);
    }
});

// -------------------------------------------------------- DST-safe resolution

it('resolves an ordinary local time to exactly one instant', function (): void {
    $resolved = WallClock::resolve('2026-06-15', '09:00', Timezone::of('Europe/London'));

    expect($resolved->resolution)->toBe(LocalResolution::Unique)
        ->and($resolved->instant->format('Y-m-d H:i:s'))->toBe('2026-06-15 08:00:00');
});

it('moves forward out of a spring-forward gap rather than backwards', function (): void {
    // 01:30 on 29 March 2026 does not exist in London. A shop opening at 01:30
    // should open when the clocks reach 02:00 — not at midnight the night
    // before, which is what a backwards adjustment would do.
    $resolved = WallClock::resolve('2026-03-29', '01:30', Timezone::of('Europe/London'));

    expect($resolved->resolution)->toBe(LocalResolution::Gap)
        ->and($resolved->resolution->wasAdjusted())->toBeTrue()
        ->and($resolved->instant->format('Y-m-d H:i:s'))->toBe('2026-03-29 01:00:00');
});

it('takes the earlier instant when the hour happens twice', function (): void {
    // 01:30 on 25 October 2026 occurs at both +01:00 and +00:00. A reminder
    // should fire on the first pass, not appear an hour late.
    $resolved = WallClock::resolve('2026-10-25', '01:30', Timezone::of('Europe/London'));

    expect($resolved->resolution)->toBe(LocalResolution::Overlap)
        ->and($resolved->instant->format('Y-m-d H:i:s'))->toBe('2026-10-25 00:30:00');
});

it('never returns two different instants for the same local time', function (): void {
    // The property that matters for scheduling: whatever the policy, it must be
    // deterministic, or a notification fires twice.
    $first = WallClock::resolve('2026-10-25', '01:30', Timezone::of('Europe/London'));
    $second = WallClock::resolve('2026-10-25', '01:30', Timezone::of('Europe/London'));

    expect($first->instant->getTimestamp())->toBe($second->instant->getTimestamp());
});

it('round-trips an instant through a local rendering', function (): void {
    $instant = new DateTimeImmutable('2026-08-15T14:30:00Z');
    $lagos = Timezone::of('Africa/Lagos');

    $local = WallClock::localise($instant, $lagos);

    expect($local->format('Y-m-d H:i'))->toBe('2026-08-15 15:30')
        ->and($local->getTimestamp())->toBe($instant->getTimestamp());
});

it('refuses a local date that does not exist instead of silently moving it', function (): void {
    // PHP would parse '2026-02-30' into 2 March. A merchant's opening hours
    // quietly landing on a different day is worse than an error.
    expect(fn () => WallClock::resolve('2026-02-30', '09:00', Timezone::utc()))
        ->toThrow(InvalidArgumentException::class, 'not a valid local date');
});

it('refuses a malformed local time', function (string $bad): void {
    expect(fn () => WallClock::resolve('2026-06-15', $bad, Timezone::utc()))
        ->toThrow(InvalidArgumentException::class);
})->with(['25:00', '9am', '', '12:60']);
