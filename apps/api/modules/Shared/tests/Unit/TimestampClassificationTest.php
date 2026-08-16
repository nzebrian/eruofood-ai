<?php

declare(strict_types=1);

use EruoFood\Shared\Domain\Time\BackfillCategory;
use EruoFood\Shared\Domain\Time\TimestampColumnClassifier;

/**
 * The guard on a one-way rewrite of historical data.
 *
 * The first version of the UTC backfill shifted every `timestamp` column it
 * discovered — 262 of them — on the assumption that SQL type implies
 * provenance. It does not. These tests pin the columns where that assumption
 * would have corrupted data.
 */
$classify = static fn (string $table, string $column) => (new TimestampColumnClassifier())->classify($table, $column);

// ------------------------------------------------ columns that MUST NOT shift

it('never converts a rider-supplied document expiry', function (string $column) use ($classify): void {
    // The concrete harm: a rider sends `2027-01-15`, PHP parses it at midnight,
    // and a minus-one-hour shift expires their insurance on the 14th. Across
    // the fleet that is a dispatch outage nobody would connect to a timezone
    // migration.
    $result = $classify('dispatch_vehicles', $column);

    expect($result->isConverted())->toBeFalse()
        ->and($result->category)->toBe(BackfillCategory::DateOnly);
})->with(['insurance_expires_at', 'roadworthiness_expires_at', 'licence_expires_at']);

it('never converts a settlement period boundary', function (string $column) use ($classify): void {
    // A period starting `2026-08-01 00:00:00` becoming `2026-07-31 23:00:00`
    // moves a day of takings into the previous settlement.
    expect($classify('payments_settlements', $column)->isConverted())->toBeFalse();
})->with(['period_start', 'period_end']);

it('never converts a column whose rows have two different provenances', function (array $case) use ($classify): void {
    [$table, $column] = $case;

    $result = $classify($table, $column);

    expect($result->isConverted())->toBeFalse()
        ->and($result->category)->toBe(BackfillCategory::UnverifiedOrMixed);
})->with([
    // Provider `expiresOn` when supplied, clock-derived fallback when not.
    [['verification_cases', 'expires_at']],
    // Caller-supplied send time, or a clock-derived quiet-hours deferral.
    [['notifications_notifications', 'scheduled_for']],
    [['notifications_broadcasts', 'scheduled_for']],
    [['marketplace_orders', 'scheduled_for']],
    [['commerce_orders', 'scheduled_for']],
]);

it('never converts an operator-chosen campaign window', function (array $case) use ($classify): void {
    [$table, $column] = $case;

    expect($classify($table, $column)->isConverted())->toBeFalse();
})->with([
    [['admin_banners', 'starts_at']],
    [['admin_banners', 'ends_at']],
    [['commerce_promotions', 'starts_at']],
    [['commerce_promotions', 'ends_at']],
    [['commerce_coupons', 'expires_at']],
    [['loyalty_rewards', 'starts_at']],
    [['loyalty_rewards', 'ends_at']],
]);

it('never converts its own audit trail', function () use ($classify): void {
    // Written with the database clock precisely so it stays readable across the
    // change it is recording.
    expect($classify('shared_timezone_backfill_log', 'recorded_at')->isConverted())->toBeFalse()
        ->and($classify('migrations', 'anything')->category)->toBe(BackfillCategory::SystemMetadata);
});

it('excludes anything it does not recognise, rather than guessing', function () use ($classify): void {
    // The safe failure mode for a one-way rewrite. This is how the four
    // `scheduled_for` columns surfaced in the first place.
    $result = $classify('some_future_table', 'a_column_nobody_classified');

    expect($result->isConverted())->toBeFalse()
        ->and($result->category)->toBe(BackfillCategory::UnverifiedOrMixed);
});

// ---------------------------------------------------- columns that MUST shift

it('converts the clock-derived instants the cutover exists for', function (array $case) use ($classify): void {
    [$table, $column] = $case;

    expect($classify($table, $column)->isConverted())->toBeTrue();
})->with([
    // Financial (M23): ledger entries must move with everything else, or every
    // interval spanning the cutover is an hour wrong.
    [['payments_ledger_entries', 'created_at']],
    // KYC (M24).
    [['verification_cases', 'created_at']],
    [['verification_cases', 'decided_at']],
    // Geo (M25): rider positions, whose staleness window is 300 seconds — an
    // unshifted hour would make every rider look permanently stale.
    [['geo_rider_locations', 'recorded_at']],
    // Dispatch (M26): offer deadlines.
    [['dispatch_offers', 'expires_at']],
    [['dispatch_offers', 'offered_at']],
    [['dispatch_assignments', 'accepted_at']],
    // Audit.
    [['admin_audit_entries', 'created_at']],
]);

it('treats the same column name differently depending on the table', function () use ($classify): void {
    // The reason the exclusion list is written out per table rather than
    // matched by name: `expires_at` is a clock-derived deadline in eleven
    // tables and an operator-chosen date in two.
    expect($classify('dispatch_offers', 'expires_at')->isConverted())->toBeTrue()
        ->and($classify('commerce_coupons', 'expires_at')->isConverted())->toBeFalse();
});

it('reports a reason for every exclusion', function () use ($classify): void {
    // "Why was this column left alone?" is asked long after whoever decided it
    // has moved on.
    foreach ([
        ['dispatch_vehicles', 'licence_expires_at'],
        ['payments_settlements', 'period_start'],
        ['verification_cases', 'expires_at'],
        ['shared_timezone_backfill_log', 'recorded_at'],
    ] as [$table, $column]) {
        expect($classify($table, $column)->reason)->not->toBe('');
    }
});
