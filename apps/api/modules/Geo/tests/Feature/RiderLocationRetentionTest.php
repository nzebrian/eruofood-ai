<?php

declare(strict_types=1);

use EruoFood\Geo\Infrastructure\Persistence\Eloquent\Model\RiderLocationModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * M42 — `geo:purge-rider-locations`.
 *
 * The most sensitive delete on the platform, and the one most worth getting the
 * direction of the comparison right on. `geo_rider_locations` holds one row per
 * rider — their latest position, not a trail — so an off-by-one on the cutoff
 * does not trim history, it removes a working rider from dispatch.
 *
 * Every value here is synthetic: fixed coordinates in the Gulf of Guinea and
 * generated UUIDs. No real rider, account or position appears in this file.
 */

/** A stored position for a rider who last reported at the given time. */
function position(string $label, string $recordedAt): string
{
    $riderId = (string) Str::orderedUuid();

    RiderLocationModel::query()->create([
        'rider_id' => $riderId,
        'user_id' => (string) Str::orderedUuid(),
        // Deliberately 0,0 — a real Lagos coordinate in a fixture reads like a
        // real rider's position to somebody skimming.
        'latitude' => 0.0,
        'longitude' => 0.0,
        'accuracy_metres' => 12.5,
        'heading_degrees' => null,
        'speed_mps' => null,
        'source' => 'device',
        'recorded_at' => $recordedAt,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // The label is the caller's handle on the row; the table has no column for
    // it, so the mapping stays in the test.
    return $riderId;
}

function storedRiders(): array
{
    return RiderLocationModel::query()->pluck('rider_id')->all();
}

/** @return array{0: int, 1: string} */
function runGeo(array $options = []): array
{
    $code = Artisan::call('geo:purge-rider-locations', $options);

    return [$code, Artisan::output()];
}

beforeEach(function (): void {
    // The command builds its cutoff from `now()`, so Carbon travel is enough
    // here — unlike the idempotency store, which reads the Clock port.
    $this->travelTo(new DateTimeImmutable('2027-06-01 12:00:00', new DateTimeZone('UTC')));
});

it('removes a position last recorded before the window and keeps one inside it', function (): void {
    $stale = position('stale', '2027-04-01 12:00:00');   // 61 days ago
    $fresh = position('fresh', '2027-05-30 12:00:00');   // 2 days ago

    [$code, $out] = runGeo();

    expect($code)->toBe(0)
        ->and(storedRiders())->toBe([$fresh])
        ->and(storedRiders())->not->toContain($stale)
        ->and($out)->toContain('Purged 1 of 1');
});

it('treats a position recorded exactly at the cutoff as inside the window', function (): void {
    // Strictly `<`. A rider who reported exactly 30 days ago to the second is
    // not eligible, and the difference between `<` and `<=` here is one working
    // rider silently dropped out of dispatch.
    $boundary = position('boundary', '2027-05-02 12:00:00');

    [$code] = runGeo(['--days' => 30]);

    expect($code)->toBe(0)
        ->and(storedRiders())->toBe([$boundary]);
});

it('measures the window backwards from now, not forwards', function (): void {
    // Guards the sign of the `modify()`. With `+%d days` the cutoff lands in the
    // future and BOTH rows below are eligible — including the one recorded a
    // minute ago. That failure looks like a working purge until a rider
    // disappears mid-shift.
    $justNow = position('just-now', '2027-06-01 11:59:00');
    position('ancient', '2020-01-01 00:00:00');

    [, $out] = runGeo();

    expect(storedRiders())->toBe([$justNow])
        ->and($out)->toContain('Purged 1 of 1');
});

it('changes nothing on a dry run', function (): void {
    $stale = position('stale', '2027-01-01 12:00:00');

    [$code, $out] = runGeo(['--dry-run' => true]);

    expect($code)->toBe(0)
        ->and($out)->toContain('Dry run')
        ->and($out)->toContain('Nothing was deleted')
        ->and(storedRiders())->toBe([$stale]);
});

it('refuses a non-positive window instead of emptying the table', function (): void {
    // `--days=0` would put the cutoff at `now` and delete every position on the
    // platform, live riders included. This must fail, not run.
    $stale = position('stale', '2020-01-01 00:00:00');

    foreach ([0, -30] as $days) {
        [$code, $out] = runGeo(['--days' => $days]);

        expect($code)->toBe(1)
            ->and($out)->toContain('positive');
    }

    expect(storedRiders())->toBe([$stale]);
});

it('refuses a non-positive chunk', function (): void {
    $stale = position('stale', '2020-01-01 00:00:00');

    foreach ([0, -5] as $chunk) {
        [$code, $out] = runGeo(['--chunk' => $chunk]);

        expect($code)->toBe(1)
            ->and($out)->toContain('positive');
    }

    expect(storedRiders())->toBe([$stale]);
});

it('purges a backlog larger than one chunk', function (): void {
    for ($i = 0; $i < 25; $i++) {
        position(sprintf('stale-%02d', $i), '2027-01-01 12:00:00');
    }
    $fresh = position('fresh', '2027-05-31 12:00:00');

    [$code, $out] = runGeo(['--chunk' => 4]);

    // Chunking bounds the statement, not the outcome: everything eligible goes.
    expect($code)->toBe(0)
        ->and($out)->toContain('Purged 25 of 25')
        ->and(storedRiders())->toBe([$fresh]);
});

it('prints no coordinate, rider id or user id', function (): void {
    $riderId = position('stale', '2020-01-01 00:00:00');
    $userId = (string) RiderLocationModel::query()->sole()->user_id;

    RiderLocationModel::query()->update(['latitude' => 6.5244, 'longitude' => 3.3792]);

    [, $out] = runGeo();

    // A command whose purpose is to stop storing where people were should not
    // read those positions out to a terminal on its way to deleting them.
    expect($out)->not->toContain($riderId)
        ->not->toContain($userId)
        ->not->toContain('6.5244')
        ->not->toContain('3.3792');
});
