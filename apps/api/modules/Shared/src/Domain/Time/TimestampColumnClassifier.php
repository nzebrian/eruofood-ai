<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\Time;

/**
 * Decides, per column, whether the UTC cutover may touch it.
 *
 * ## The rule
 *
 * A column is converted only when every value in it was written by this
 * application from its own clock. That is a statement about *provenance*, and
 * provenance cannot be read off the SQL type — which is why the first version
 * of the backfill, which shifted all 262 discovered columns, was unsafe.
 *
 * ## How provenance was established
 *
 * By reading the write sites. Three findings drive the exclusion list:
 *
 * 1. **Client-supplied document dates.** `RiderVehicleController::updateDocuments()`
 *    validates `insurance_expires_at` and friends with Laravel's `date` rule and
 *    builds them with `new DateTimeImmutable($input)`. A rider's app sends
 *    `2027-01-15`; PHP parses that at midnight in the default timezone. Shifting
 *    it back an hour expires the document a day early — for every rider.
 *
 * 2. **Provider dates with a clock-derived fallback.** M24's
 *    `VerificationService::parseDate()` stores whatever the identity provider
 *    returned as `expiresOn`, and falls back to `$now->modify('+N days')` when
 *    the provider sends nothing. So `verification_cases.expires_at` holds rows
 *    of *both* kinds and no single shift is correct for the column.
 *
 * 3. **Business calendar windows.** Campaign, promotion and settlement period
 *    boundaries are dates an operator chose, not moments the server observed. A
 *    settlement period that starts at `2026-08-01 00:00:00` must not become
 *    `2026-07-31 23:00:00`, or a day's takings land in the wrong period.
 *
 * ## The default is exclusion
 *
 * Anything not positively classified is {@see BackfillCategory::UnverifiedOrMixed}
 * and is left alone. For a one-way rewrite of historical financial, KYC and
 * dispatch data, the safe failure mode is to skip a column and report it, never
 * to guess. `shared:timezone-manifest` prints everything so nothing stays
 * silently unclassified.
 */
final readonly class TimestampColumnClassifier
{
    /**
     * Columns excluded from the cutover, with the reason for each.
     *
     * Keyed `table.column`. Written out in full rather than pattern-matched:
     * `expires_at` is category A in eleven tables and category F in two, so a
     * name-based rule would be wrong in both directions.
     *
     * @var array<string, array{0: BackfillCategory, 1: string}>
     */
    private const EXCLUSIONS = [
        // 1. Client-supplied document dates (M26 vehicles).
        'dispatch_vehicles.insurance_expires_at' => [BackfillCategory::DateOnly, 'Rider-supplied document date, validated as `date` and parsed at midnight. Shifting expires the policy a day early.'],
        'dispatch_vehicles.roadworthiness_expires_at' => [BackfillCategory::DateOnly, 'Rider-supplied document date, parsed at midnight. Shifting expires the certificate a day early.'],
        'dispatch_vehicles.licence_expires_at' => [BackfillCategory::DateOnly, 'Rider-supplied document date, parsed at midnight. Shifting expires the licence a day early.'],

        // 2. Provider dates with a clock-derived fallback (M24 KYC/KYB).
        'verification_cases.expires_at' => [BackfillCategory::UnverifiedOrMixed, 'Holds either the identity provider\'s `expiresOn` or a clock-derived fallback, so rows in one column have two different provenances.'],

        // 3. Business calendar windows chosen by an operator.
        'payments_settlements.period_start' => [BackfillCategory::DateOnly, 'Settlement period boundary. Shifting moves a day of takings into the previous period.'],
        'payments_settlements.period_end' => [BackfillCategory::DateOnly, 'Settlement period boundary. Shifting moves a day of takings into the previous period.'],
        'admin_banners.starts_at' => [BackfillCategory::DateOnly, 'Operator-chosen campaign window, supplied as a date string.'],
        'admin_banners.ends_at' => [BackfillCategory::DateOnly, 'Operator-chosen campaign window, supplied as a date string.'],
        'commerce_promotions.starts_at' => [BackfillCategory::DateOnly, 'Operator-chosen campaign window, supplied as a date string.'],
        'commerce_promotions.ends_at' => [BackfillCategory::DateOnly, 'Operator-chosen campaign window, supplied as a date string.'],
        'commerce_coupons.expires_at' => [BackfillCategory::DateOnly, 'Operator-chosen coupon expiry, supplied as a date string.'],
        'loyalty_rewards.starts_at' => [BackfillCategory::DateOnly, 'Operator-chosen campaign window, supplied as a date string via LoyaltyAdminController::date().'],
        'loyalty_rewards.ends_at' => [BackfillCategory::DateOnly, 'Operator-chosen campaign window, supplied as a date string via LoyaltyAdminController::date().'],

        // 4. Scheduled sends and scheduled orders — two write paths, two
        //    provenances. `NotificationService::resolveSchedule()` returns the
        //    caller's `scheduledFor` when one was given (an admin or customer
        //    string, whose zone depends on what the client sent) and otherwise
        //    derives a quiet-hours deferral from the server clock. Rows of both
        //    kinds sit in one column, so no single shift is right for all of
        //    them. Surfaced by the classifier's fail-safe rather than
        //    anticipated — which is the fail-safe doing its job.
        'notifications_notifications.scheduled_for' => [BackfillCategory::UnverifiedOrMixed, 'Either a caller-supplied send time or a clock-derived quiet-hours deferral; one column, two provenances.'],
        'notifications_broadcasts.scheduled_for' => [BackfillCategory::UnverifiedOrMixed, 'Admin-supplied broadcast send time parsed from a request string, whose zone depends on what the client sent.'],
        'marketplace_orders.scheduled_for' => [BackfillCategory::UnverifiedOrMixed, 'Customer-chosen delivery time supplied as a string; zone depends on the client, so it is not reliably Lagos wall-clock.'],
        'commerce_orders.scheduled_for' => [BackfillCategory::UnverifiedOrMixed, 'Customer-chosen delivery time supplied as a string; zone depends on the client, so it is not reliably Lagos wall-clock.'],

        // 5. The cutover's own bookkeeping.
        'shared_timezone_backfill_log.recorded_at' => [BackfillCategory::SystemMetadata, 'Written with the database clock, by the migration itself, to stay readable across the change.'],
    ];

    /** Tables excluded wholesale. */
    private const EXCLUDED_TABLES = [
        'shared_timezone_backfill_log' => 'The cutover\'s own audit trail.',
        'migrations' => 'Laravel bookkeeping; carries no domain timestamp.',
    ];

    /**
     * Columns positively confirmed as clock-derived instants.
     *
     * Laravel's own `created_at`/`updated_at` plus the `*_at` event stamps this
     * platform writes with `Clock::now()`. Listed as name patterns because the
     * platform is consistent about them and the exclusion list above carves out
     * the specific places where a same-named column means something else.
     *
     * @var list<string>
     */
    private const CLOCK_DERIVED_SUFFIXES = ['_at', '_on'];

    /** @var list<string> */
    private const CLOCK_DERIVED_EXACT = ['created_at', 'updated_at', 'deleted_at'];

    public function classify(string $table, string $column): ColumnClassification
    {
        if (isset(self::EXCLUDED_TABLES[$table])) {
            return new ColumnClassification(
                $table,
                $column,
                BackfillCategory::SystemMetadata,
                self::EXCLUDED_TABLES[$table],
            );
        }

        $key = "{$table}.{$column}";

        if (isset(self::EXCLUSIONS[$key])) {
            [$category, $reason] = self::EXCLUSIONS[$key];

            return new ColumnClassification($table, $column, $category, $reason);
        }

        if (in_array($column, self::CLOCK_DERIVED_EXACT, true)) {
            return new ColumnClassification(
                $table,
                $column,
                BackfillCategory::ConvertibleInstant,
                'Eloquent timestamp, written from the application clock.',
            );
        }

        foreach (self::CLOCK_DERIVED_SUFFIXES as $suffix) {
            if (str_ends_with($column, $suffix)) {
                return new ColumnClassification(
                    $table,
                    $column,
                    BackfillCategory::ConvertibleInstant,
                    'Event or deadline stamp written from the application clock.',
                );
            }
        }

        // Not recognised. Excluded, and reported so somebody classifies it
        // rather than it being shifted on a guess.
        return new ColumnClassification(
            $table,
            $column,
            BackfillCategory::UnverifiedOrMixed,
            'Not matched by any classification rule; excluded pending manual review.',
        );
    }
}
