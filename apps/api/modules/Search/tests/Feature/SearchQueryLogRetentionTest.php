<?php

declare(strict_types=1);

use EruoFood\Search\Domain\Analytics\PopularTerm;
use EruoFood\Search\Domain\Analytics\SearchAnalyticsRepository;
use EruoFood\Search\Domain\Enum\SearchType;
use EruoFood\Shared\Domain\DataLifecycle\DataCategory;
use EruoFood\Shared\Domain\DataLifecycle\DeletionMode;
use EruoFood\Shared\Domain\DataLifecycle\RetentionRegistry;
use EruoFood\Shared\Domain\Schedule\Cadence;
use EruoFood\Shared\Domain\Schedule\ScheduledTask;
use EruoFood\Shared\Domain\Schedule\ScheduleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * M40-SEC-001 — the search query log stops being kept forever.
 *
 * `search_query_log` stores the VERBATIM text somebody typed alongside their
 * `user_id`, written on every executed search. Nothing removed a row, so the
 * platform accumulated an attributable record of what every user searched for,
 * indefinitely. M39 constrained what is PUBLISHED from that table; this is the
 * storage half.
 *
 * All fixture terms are synthetic labels. No real names, health information or
 * personal data enters this repository's test data.
 */

/**
 * A stable UUID for a named fixture actor.
 *
 * `user_id` is a `uuid` column: SQLite accepts any string, PostgreSQL rejects
 * one with SQLSTATE[22P02]. Hashing a readable label keeps the fixture legible
 * and runnable on both engines.
 */
function retentionActor(string $label): string
{
    $h = md5($label);

    return sprintf(
        '%s-%s-4%s-8%s-%s',
        substr($h, 0, 8),
        substr($h, 8, 4),
        substr($h, 13, 3),
        substr($h, 17, 3),
        substr($h, 20, 12),
    );
}

/**
 * Insert a query-log row at a DETERMINISTIC timestamp.
 *
 * Written directly rather than through `recordQuery()`, which always stamps
 * `now()` — retention is entirely about `created_at`, so the test has to own it.
 */
function logAt(string $term, string $createdAt, string $actor = 'retention-user'): string
{
    $id = (string) Str::orderedUuid();

    DB::table('search_query_log')->insert([
        'id' => $id,
        'term' => $term,
        'type' => SearchType::Global->value,
        'result_count' => 1,
        'user_id' => retentionActor($actor),
        'created_at' => $createdAt,
    ]);

    return $id;
}

function rowCount(): int
{
    return (int) DB::table('search_query_log')->count();
}

beforeEach(function (): void {
    // A deterministic clock, so "90 days ago" is exact rather than drifting
    // with wall time between the seed and the assertion.
    $this->travelTo(new DateTimeImmutable('2027-03-01 12:00:00'));
});

// =============================================================================
// Eligibility and preservation
// =============================================================================

it('treats rows older than the window as eligible and recent rows as not', function (): void {
    logAt('OLD FIXTURE TERM', '2026-11-01 12:00:00');   // ~120 days old
    logAt('RECENT FIXTURE TERM', '2027-02-20 12:00:00'); // ~9 days old

    $analytics = app(SearchAnalyticsRepository::class);
    $cutoff = new DateTimeImmutable('2027-03-01 12:00:00 -90 days');

    expect($analytics->countQueriesBefore($cutoff))->toBe(1)
        ->and(rowCount())->toBe(2);
});

it('deletes only rows older than the cutoff', function (): void {
    // Both comfortably older than the 2026-12-01 cutoff. (An earlier draft put
    // one at exactly 2026-12-01 12:00:00 and it survived — correctly, since the
    // rule is strictly `<`. The boundary case has its own test below.)
    logAt('OLD FIXTURE TERM', '2026-11-01 12:00:00');
    logAt('ALSO OLD FIXTURE TERM', '2026-11-15 12:00:00');
    logAt('RECENT FIXTURE TERM', '2027-02-20 12:00:00');

    $removed = app(SearchAnalyticsRepository::class)
        ->purgeQueriesBefore(new DateTimeImmutable('2027-03-01 12:00:00 -90 days'), 1000);

    expect($removed)->toBe(2)
        ->and(rowCount())->toBe(1)
        ->and(DB::table('search_query_log')->value('term'))->toBe('RECENT FIXTURE TERM');
});

it('keeps a row sitting exactly on the boundary', function (): void {
    // Strictly `<` the cutoff. A row AT the cutoff is inside the window.
    $cutoff = new DateTimeImmutable('2027-03-01 12:00:00 -90 days');

    logAt('BOUNDARY EXACT TERM', $cutoff->format('Y-m-d H:i:s'));
    logAt('BOUNDARY ONE SECOND OLDER', $cutoff->modify('-1 second')->format('Y-m-d H:i:s'));

    $removed = app(SearchAnalyticsRepository::class)->purgeQueriesBefore($cutoff, 1000);

    expect($removed)->toBe(1)
        ->and(DB::table('search_query_log')->value('term'))->toBe('BOUNDARY EXACT TERM');
});

// =============================================================================
// The command
// =============================================================================

/**
 * Run the command and return [exitCode, output].
 *
 * `Artisan::call()` rather than a chain of `expectsOutputToContain()`: the
 * command prints ONE line, and Laravel's pending-command matcher consumes an
 * output expectation per line, so several expectations against a single line
 * cannot all be satisfied. Capturing the text lets each assertion look at the
 * whole of it.
 *
 * @return array{int, string}
 */
function runPurge(array $options = []): array
{
    $code = Illuminate\Support\Facades\Artisan::call('search:purge-query-log', $options);

    return [$code, Illuminate\Support\Facades\Artisan::output()];
}

it('reports without deleting on a dry run', function (): void {
    logAt('OLD FIXTURE TERM', '2026-11-01 12:00:00');
    logAt('RECENT FIXTURE TERM', '2027-02-20 12:00:00');

    [$code, $output] = runPurge(['--dry-run' => true]);

    expect($code)->toBe(0)
        ->and($output)->toContain('Dry run')
        ->and($output)->toContain('1 query-log row(s)')
        ->and($output)->toContain('Nothing was deleted');

    // Destroy is irreversible; a dry run that deleted would be indefensible.
    expect(rowCount())->toBe(2);
});

it('purges and reports counts on a real run', function (): void {
    logAt('OLD FIXTURE TERM', '2026-11-01 12:00:00');
    logAt('RECENT FIXTURE TERM', '2027-02-20 12:00:00');

    [$code, $output] = runPurge();

    expect($code)->toBe(0)
        ->and($output)->toContain('Purged 1 of 1 eligible query-log row(s)')
        ->and(rowCount())->toBe(1);
});

it('is idempotent — a second run removes nothing and still succeeds', function (): void {
    logAt('OLD FIXTURE TERM', '2026-11-01 12:00:00');

    [$first] = runPurge();
    expect($first)->toBe(0)->and(rowCount())->toBe(0);

    [$second, $output] = runPurge();
    expect($second)->toBe(0)
        ->and($output)->toContain('Purged 0 of 0')
        ->and(rowCount())->toBe(0);
});

it('honours an explicit --days override', function (): void {
    logAt('THIRTY FIVE DAYS OLD', '2027-01-25 12:00:00');

    // Inside the configured 90-day window …
    [$code, $output] = runPurge(['--dry-run' => true]);
    expect($code)->toBe(0)->and($output)->toContain('0 query-log row(s)');

    // … but outside an explicit 30-day one.
    [$code] = runPurge(['--days' => 30]);
    expect($code)->toBe(0)->and(rowCount())->toBe(0);
});

it('fails safely on a non-positive retention window instead of emptying the table', function (): void {
    logAt('SHOULD SURVIVE MISCONFIGURATION', '2026-11-01 12:00:00');

    foreach ([0, -1] as $days) {
        [$code, $output] = runPurge(['--days' => $days]);
        expect($code)->toBe(1)->and($output)->toContain('must be a positive number of days');
    }

    // A window of 0 would otherwise mean "delete everything, including the
    // search somebody ran a second ago".
    expect(rowCount())->toBe(1);
});

it('fails safely on a non-positive chunk size', function (): void {
    logAt('OLD FIXTURE TERM', '2026-11-01 12:00:00');

    [$code, $output] = runPurge(['--chunk' => 0]);
    expect($code)->toBe(1)->and($output)->toContain('Chunk size must be a positive');

    expect(rowCount())->toBe(1);
});

it('never prints a query term or a user id', function (): void {
    // The command exists BECAUSE query strings are sensitive. Echoing them into
    // operator logs on the way to deleting them would defeat its purpose.
    $actor = retentionActor('retention-user');
    logAt('SECRET FIXTURE TERM', '2026-11-01 12:00:00');

    [$dry, $dryOutput] = runPurge(['--dry-run' => true]);
    [, $realOutput] = runPurge();

    expect($dry)->toBe(0)
        ->and($dryOutput)->not->toContain('SECRET FIXTURE TERM')
        ->and($dryOutput)->not->toContain($actor)
        ->and($realOutput)->not->toContain('SECRET FIXTURE TERM')
        ->and($realOutput)->not->toContain($actor);
});

// =============================================================================
// Bounded memory
// =============================================================================

it('deletes in bounded batches rather than loading the table', function (): void {
    for ($i = 0; $i < 25; $i++) {
        logAt(sprintf('BATCH FIXTURE TERM %02d', $i), '2026-11-01 12:00:00');
    }

    $selects = 0;
    DB::listen(function ($q) use (&$selects): void {
        if (str_starts_with(strtolower(trim($q->sql)), 'select') && str_contains($q->sql, 'search_query_log')) {
            $selects++;
        }
    });

    $removed = app(SearchAnalyticsRepository::class)
        ->purgeQueriesBefore(new DateTimeImmutable('2027-03-01 12:00:00 -90 days'), 5);

    // 25 rows at 5 per batch: five full batches plus a final empty probe. If the
    // implementation had pulled every id in one statement there would be one
    // SELECT, so this asserts the batching is real and bounded by chunk size.
    expect($removed)->toBe(25)
        ->and(rowCount())->toBe(0)
        ->and($selects)->toBeGreaterThan(1);
});

it('performs a single cheap probe when there is nothing to purge', function (): void {
    logAt('RECENT FIXTURE TERM', '2027-02-20 12:00:00');

    $deletes = 0;
    DB::listen(function ($q) use (&$deletes): void {
        if (str_starts_with(strtolower(trim($q->sql)), 'delete')) {
            $deletes++;
        }
    });

    expect(app(SearchAnalyticsRepository::class)
        ->purgeQueriesBefore(new DateTimeImmutable('2027-03-01 12:00:00 -90 days'), 1000))->toBe(0)
        ->and($deletes)->toBe(0);
});

// =============================================================================
// Policy and schedule registration
// =============================================================================

it('declares a retention policy for the query log', function (): void {
    $policy = RetentionRegistry::platformDefaults()->get('search.query_log');

    expect($policy->category)->toBe(DataCategory::OperationalRecord)
        ->and($policy->deletionMode)->toBe(DeletionMode::Destroy)
        ->and($policy->retainDays)->toBe((int) config('search.query_log_retention_days'))
        ->and($policy->retainDays)->toBeGreaterThan(0)
        ->and($policy->isIndefinite())->toBeFalse()
        ->and($policy->purpose)->not->toBe('')
        ->and($policy->accessPolicy)->not->toBe('');

    // The category matters: query strings are about a person, so they must not
    // be treated as transient technical data that may enter telemetry.
    expect($policy->category->mayEnterTelemetry())->toBeFalse()
        ->and($policy->category->honoursErasureRequest())->toBeTrue();
});

it('registers the purge as a scheduled task that is switched off', function (): void {
    $tasks = app(ScheduleRegistry::class)->all();
    $purge = null;

    foreach ($tasks as $task) {
        if ($task->name === 'search:purge-query-log') {
            $purge = $task;
        }
    }

    expect($purge)->not->toBeNull()
        ->and($purge->command)->toBe('search:purge-query-log')
        ->and($purge->cadence)->toBe(Cadence::Daily)
        ->and($purge->description)->not->toBe('');

    // Registered but NOT scheduled: an unattended irreversible delete against
    // production data is an operator decision, matching the convention every
    // other task in this registry already follows.
    expect($purge->enabled)->toBeFalse();

    $enabledNames = array_map(static fn (ScheduledTask $t): string => $t->name, app(ScheduleRegistry::class)->enabled());
    expect($enabledNames)->not->toContain('search:purge-query-log');
});

// =============================================================================
// Analytics for retained rows are unaffected
// =============================================================================

it('leaves analytics intact for the rows that remain', function (): void {
    logAt('OLD FIXTURE TERM', '2026-11-01 12:00:00');
    for ($i = 0; $i < 3; $i++) {
        logAt('RETAINED FIXTURE TERM', '2027-02-20 12:00:00');
    }

    $analytics = app(SearchAnalyticsRepository::class);
    $analytics->purgeQueriesBefore(new DateTimeImmutable('2027-03-01 12:00:00 -90 days'), 1000);

    $terms = array_map(static fn (PopularTerm $t): string => $t->term, $analytics->popular(365, 100));

    expect($terms)->toContain('RETAINED FIXTURE TERM')
        ->not->toContain('OLD FIXTURE TERM');

    // And the public boundary still applies to what survives (M39 unchanged).
    expect(array_map(
        static fn (PopularTerm $t): string => $t->term,
        $analytics->publicTerms(365, 100, 3),
    ))->toContain('RETAINED FIXTURE TERM');
});

it('purges only the query log and no neighbouring table', function (): void {
    logAt('OLD FIXTURE TERM', '2026-11-01 12:00:00');

    $sourceId = (string) Str::orderedUuid();
    DB::table('search_documents')->insert([
        'id' => 'food:'.$sourceId, 'type' => 'food', 'source_id' => $sourceId,
        'title' => 'Retention Neighbour Dish', 'description' => 'untouched',
        'search_text' => 'retention neighbour dish', 'keywords' => json_encode([]),
        'locale' => 'en', 'facets' => json_encode([]), 'popularity' => 1, 'rating' => 0,
        'embedding' => json_encode([]),
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);

    app(SearchAnalyticsRepository::class)
        ->purgeQueriesBefore(new DateTimeImmutable('2027-03-01 12:00:00 -90 days'), 1000);

    // The document is far older than the cutoff and must be untouched — the
    // purge is scoped to one table, not to "anything with an old timestamp".
    expect(DB::table('search_documents')->count())->toBe(1)
        ->and(rowCount())->toBe(0);
});
