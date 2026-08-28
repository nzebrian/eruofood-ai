<?php

declare(strict_types=1);

use EruoFood\Search\Domain\Analytics\SearchAnalyticsRepository;
use EruoFood\Shared\Domain\DataLifecycle\RetentionRegistry;
use EruoFood\Shared\Domain\Schedule\Cadence;
use EruoFood\Shared\Domain\Schedule\ScheduledTask;
use EruoFood\Shared\Domain\Schedule\ScheduleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * M40 — controls on the M40-SEC-001 controls.
 *
 * `SearchQueryLogRetentionTest` passes. So would a version of it that asserted
 * nothing — and the thing under test here DELETES DATA IRREVERSIBLY, so a
 * vacuous assertion is not a cosmetic problem. Each control below drives the
 * real repository against real rows and proves the paired assertion can tell a
 * correct purge from a dangerous one.
 *
 * The specific reversals these guard against:
 *
 *   - cutoff direction reversed (recent deleted, old kept)
 *   - a dry run that actually deletes
 *   - the retention policy quietly not registered
 *   - the scheduler ignoring `enabled`
 *
 * The most important one — cutoff direction — is also mutation-verified against
 * the production file by an external harness, with sha256-checked restoration;
 * results are recorded in the PR rather than re-run by CI.
 */

/** Insert a row at an exact timestamp. Synthetic labels only. */
function controlRow(string $term, string $createdAt): void
{
    DB::table('search_query_log')->insert([
        'id' => (string) Illuminate\Support\Str::orderedUuid(),
        'term' => $term,
        'type' => 'global',
        'result_count' => 1,
        'user_id' => null,
        'created_at' => $createdAt,
    ]);
}

/** @return list<string> */
function survivingTerms(): array
{
    /** @var list<string> $terms */
    $terms = DB::table('search_query_log')->orderBy('created_at')->pluck('term')->all();

    return $terms;
}

beforeEach(function (): void {
    $this->travelTo(new DateTimeImmutable('2027-03-01 12:00:00'));
});

// =============================================================================
// Cutoff direction
// =============================================================================

it('M40-SEC-001 · A · deletes the OLD side of the cutoff, never the recent side', function (): void {
    controlRow('ANCIENT CONTROL TERM', '2026-01-01 12:00:00');
    controlRow('RECENT CONTROL TERM', '2027-02-28 12:00:00');

    $removed = app(SearchAnalyticsRepository::class)
        ->purgeQueriesBefore(new DateTimeImmutable('2026-12-01 12:00:00'), 1000);

    // Direction is the whole safety property. A reversed comparison would give
    // removed=1 as well — so the assertion is on WHICH row survived, not on the
    // count, which is what makes this control discriminating rather than
    // merely arithmetic.
    expect($removed)->toBe(1)
        ->and(survivingTerms())->toBe(['RECENT CONTROL TERM'])
        ->and(survivingTerms())->not->toContain('ANCIENT CONTROL TERM');
});

it('M40-SEC-001 · A · a purge with an ancient cutoff removes nothing at all', function (): void {
    controlRow('ANCIENT CONTROL TERM', '2026-01-01 12:00:00');
    controlRow('RECENT CONTROL TERM', '2027-02-28 12:00:00');

    // Everything is NEWER than this cutoff, so a correct implementation is a
    // no-op. An implementation that ignored the cutoff, or reversed it, would
    // empty the table here.
    $removed = app(SearchAnalyticsRepository::class)
        ->purgeQueriesBefore(new DateTimeImmutable('2025-01-01 00:00:00'), 1000);

    expect($removed)->toBe(0)
        ->and(survivingTerms())->toHaveCount(2);
});

// =============================================================================
// Dry run
// =============================================================================

it('M40-SEC-001 · B · proves the dry-run assertion would catch a deleting dry run', function (): void {
    controlRow('DRY RUN CONTROL TERM', '2026-01-01 12:00:00');

    // The FIXED path: --dry-run leaves the row.
    Illuminate\Support\Facades\Artisan::call('search:purge-query-log', ['--dry-run' => true]);
    expect(survivingTerms())->toBe(['DRY RUN CONTROL TERM']);

    // The DANGEROUS path, reconstructed with the real repository: the same
    // cutoff without the dry-run guard removes it. If --dry-run stopped short
    // of that guard, the suite above would see this outcome instead.
    app(SearchAnalyticsRepository::class)
        ->purgeQueriesBefore(now()->toDateTimeImmutable()->modify('-90 days'), 1000);

    expect(survivingTerms())->toBe([]);
});

// =============================================================================
// Policy registration
// =============================================================================

it('M40-SEC-001 · C · proves the policy assertion is not vacuous', function (): void {
    $registry = RetentionRegistry::platformDefaults();

    // Registered …
    expect($registry->get('search.query_log'))->not->toBeNull();

    // … and the lookup genuinely fails for something that is not, so the
    // assertion above is not simply "get() always returns something".
    expect(fn () => $registry->get('search.this_policy_does_not_exist'))
        ->toThrow(EruoFood\Shared\Domain\Exception\InvalidArgumentException::class, 'Unknown retention policy');
});

it('M40-SEC-001 · C · a retention policy with a zero window would be indefinite', function (): void {
    // `isIndefinite()` is what distinguishes "declared and bounded" from
    // "declared and meaningless". The shipped policy must not be the latter.
    $policy = RetentionRegistry::platformDefaults()->get('search.query_log');

    expect($policy->isIndefinite())->toBeFalse()
        ->and($policy->retainDays)->toBeGreaterThan(0);

    // And the predicate can return true — proving the assertion above
    // discriminates rather than always holding.
    $indefinite = EruoFood\Shared\Domain\DataLifecycle\RetentionPolicy::of(
        key: 'control.indefinite',
        category: EruoFood\Shared\Domain\DataLifecycle\DataCategory::TransientTechnical,
        purpose: 'Control fixture proving isIndefinite() can be true.',
        retainDays: 0,
        deletionMode: EruoFood\Shared\Domain\DataLifecycle\DeletionMode::Destroy,
        accessPolicy: 'Control fixture; never registered.',
    );
    expect($indefinite->isIndefinite())->toBeTrue();
});

// =============================================================================
// Scheduler enablement
// =============================================================================

it('M40-SEC-001 · D · proves the registry actually honours the enabled flag', function (): void {
    // The shipped task is registered and OFF.
    $shipped = null;
    foreach (app(ScheduleRegistry::class)->all() as $task) {
        if ($task->name === 'search:purge-query-log') {
            $shipped = $task;
        }
    }
    expect($shipped)->not->toBeNull()->and($shipped->enabled)->toBeFalse();

    $enabledNames = array_map(
        static fn (ScheduledTask $t): string => $t->name,
        app(ScheduleRegistry::class)->enabled(),
    );
    expect($enabledNames)->not->toContain('search:purge-query-log');

    // A registry that ignored `enabled` would return an enabled task from
    // enabled() AND a disabled one. This proves it filters — so the assertion
    // above means something.
    $control = new ScheduleRegistry();
    $control->register(ScheduledTask::of('control:on', 'control:on', Cadence::Daily, true, 'On.'));
    $control->register(ScheduledTask::of('control:off', 'control:off', Cadence::Daily, false, 'Off.'));

    expect($control->all())->toHaveCount(2)
        ->and($control->enabled())->toHaveCount(1)
        ->and($control->enabled()[0]->name)->toBe('control:on');
});

// =============================================================================
// Positive control
// =============================================================================

it('M40 · positive control — searching and analytics still work after a purge', function (): void {
    controlRow('OLD CONTROL TERM', '2026-01-01 12:00:00');
    for ($i = 0; $i < 3; $i++) {
        controlRow('SURVIVING CONTROL TERM', '2027-02-28 12:00:00');
    }

    Illuminate\Support\Facades\Artisan::call('search:purge-query-log');

    // The ordinary public and admin surfaces are unaffected by the purge.
    $this->getJson('/api/v1/search?q=control')->assertOk();
    $this->getJson('/api/v1/search/trending')->assertOk();

    expect(survivingTerms())->not->toContain('OLD CONTROL TERM')
        ->and(survivingTerms())->toContain('SURVIVING CONTROL TERM');
});
