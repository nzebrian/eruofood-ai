<?php

declare(strict_types=1);

use EruoFood\Search\Domain\Analytics\PopularTerm;
use EruoFood\Search\Domain\Analytics\SearchAnalyticsRepository;
use EruoFood\Search\Domain\Enum\SearchType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * M39-SEC-001 — the public analytics boundary.
 *
 * ## The defect
 *
 * `/api/v1/search/trending` and `/api/v1/search/suggestions` are PUBLIC and
 * unauthenticated, and they served terms other people had typed.
 * `AutocompleteService` consumed `SearchAnalyticsRepository::trending()` and
 * `::popular()` directly — the ADMINISTRATIVE reads, which aggregate every row
 * in `search_query_log` regardless of the scope it was recorded against and
 * apply no occurrence threshold. Reproduced on `ef6288d`:
 *
 *     GET /api/v1/search/trending
 *     -> 200 {"trending":["<a term typed on the admin-only user scope>",
 *                         "<another user's one-off private phrase>", ...]}
 *
 * Three distinct exposures, all confirmed live before the fix:
 *   1. terms an administrator typed against the admin-only `user` scope;
 *   2. any authenticated user's terms on any scope;
 *   3. terms searched exactly ONCE by ONE person — no k-anonymity at all.
 *
 * ## The fix under test
 *
 * `publicTerms()` constrains `type` to `SearchType::publicScopeValues()` and
 * applies a SQL `HAVING count(*) >= search.public_term_min_occurrences`.
 *
 * ## What this does NOT claim
 *
 * Suppression, not anonymity. A term repeated often enough by one determined
 * user still qualifies. Raw query strings remain sensitive; retention is
 * M39-SEC-003 and is not addressed here.
 */

/**
 * A stable UUID for a named fixture actor.
 *
 * `search_query_log.user_id` is a `uuid` column. SQLite stores any string there
 * and PostgreSQL rejects it with SQLSTATE[22P02], so a readable label like
 * "admin-1" passes locally and fails in CI — which is exactly what happened on
 * the first push of this branch. The label is hashed into a valid v4-shaped
 * UUID so the fixtures stay readable AND run on both engines.
 */
function fixtureActor(string $label): string
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
 * Record a term `$times` on a given scope.
 *
 * Fixture terms are deliberately synthetic labels — no real names, health
 * information or personal data enters this repository's test data.
 */
function logTerm(string $term, SearchType $type, int $times, string $actor = 'fixture-user'): void
{
    $analytics = app(SearchAnalyticsRepository::class);
    $userId = fixtureActor($actor);

    for ($i = 0; $i < $times; $i++) {
        $analytics->recordQuery($term, $type, 1, $userId);
    }
}

/** The default threshold, read from config rather than hardcoded. */
function threshold(): int
{
    return (int) config('search.public_term_min_occurrences');
}

beforeEach(function (): void {
    // A. public term BELOW the threshold
    logTerm('single occurrence term', SearchType::Food, 1, 'user-a');
    logTerm('two occurrence term', SearchType::Food, 2, 'user-b');

    // B. public term MEETING the threshold, on both public scope shapes.
    //    `global` is what a default public search records; `food` is a named
    //    public scope. Both must survive the filter.
    logTerm('public trend candidate', SearchType::Global, 5, 'user-c');
    logTerm('public typed scope term', SearchType::Food, 4, 'user-d');

    // C. admin-only scope, comfortably OVER the threshold and the most frequent
    //    term in the log — so an implementation that filtered nothing but
    //    happened to order differently cannot hide behind ranking.
    logTerm('admin scope term', SearchType::User, 25, 'admin-1');

    // D. a normal user's private-looking term, also over the threshold, but
    //    recorded on the admin-only scope by an operator looking someone up.
    logTerm('private user term', SearchType::User, 9, 'admin-1');
});

// =============================================================================
// /trending
// =============================================================================

it('publishes only public-scope terms that meet the occurrence threshold', function (): void {
    $trending = $this->getJson('/api/v1/search/trending')->assertOk()->json('data.trending');

    expect($trending)
        // B — qualifying public terms appear.
        ->toContain('public trend candidate')
        ->toContain('public typed scope term')
        // C/D — admin-only scope never appears, however frequent.
        ->not->toContain('admin scope term')
        ->not->toContain('private user term')
        // A — below-threshold terms are withheld.
        ->not->toContain('single occurrence term')
        ->not->toContain('two occurrence term');
});

it('withholds a term until it reaches the threshold, then publishes it', function (): void {
    $term = 'ratchet candidate term';

    for ($count = 1; $count < threshold(); $count++) {
        logTerm($term, SearchType::Global, 1, 'user-e');
        expect($this->getJson('/api/v1/search/trending')->json('data.trending'))
            ->not->toContain($term, "term surfaced at {$count} occurrence(s), below the threshold");
    }

    // The occurrence that reaches the threshold makes it eligible.
    logTerm($term, SearchType::Global, 1, 'user-e');
    expect($this->getJson('/api/v1/search/trending')->json('data.trending'))->toContain($term);
});

it('states the boundary explicitly: 1 hidden, 2 hidden, 3 eligible', function (): void {
    expect(threshold())->toBe(3);

    logTerm('boundary one', SearchType::Global, 1);
    logTerm('boundary two', SearchType::Global, 2);
    logTerm('boundary three', SearchType::Global, 3);

    $trending = $this->getJson('/api/v1/search/trending')->assertOk()->json('data.trending');

    expect($trending)->not->toContain('boundary one')
        ->not->toContain('boundary two')
        ->toContain('boundary three');
});

// =============================================================================
// /suggestions
// =============================================================================

it('applies the same boundary to public suggestions', function (): void {
    $body = $this->getJson('/api/v1/search/suggestions?q=term')->assertOk()->getContent();

    expect($body)->not->toContain('admin scope term')
        ->and($body)->not->toContain('private user term')
        ->and($body)->not->toContain('single occurrence term')
        ->and($body)->not->toContain('two occurrence term');
});

it('still blends qualifying public history into suggestions', function (): void {
    // The fix must not be "return nothing": the history half of suggestions
    // still works for terms that qualify.
    $suggestions = $this->getJson('/api/v1/search/suggestions?q=public')->assertOk()->json('data.suggestions');

    expect($suggestions)->toContain('public trend candidate');
});

it('never leaks an admin-scope term through any public analytics route', function (): void {
    foreach (['/api/v1/search/trending', '/api/v1/search/suggestions?q=a', '/api/v1/search/suggestions?q=admin'] as $url) {
        $body = $this->getJson($url)->assertOk()->getContent();
        expect($body)->not->toContain('admin scope term')
            ->and($body)->not->toContain('private user term');
    }
});

// =============================================================================
// Administrative analytics must NOT be narrowed by the public rule
// =============================================================================

it('keeps administrative analytics unfiltered and unthresholded', function (): void {
    $analytics = app(SearchAnalyticsRepository::class);

    $terms = array_map(static fn (PopularTerm $t): string => $t->term, $analytics->popular(30, 100));

    // Operators legitimately need every scope and every term, including the
    // one-off ones — that is the whole point of zero-result analysis.
    expect($terms)->toContain('admin scope term')
        ->toContain('private user term')
        ->toContain('single occurrence term')
        ->toContain('public trend candidate');

    expect($analytics->trending(30, 100))->toContain('admin scope term');
});

it('exposes the public boundary as its own repository method', function (): void {
    $analytics = app(SearchAnalyticsRepository::class);

    $public = array_map(
        static fn (PopularTerm $t): string => $t->term,
        $analytics->publicTerms(30, 100, threshold()),
    );

    expect($public)->toContain('public trend candidate')
        ->not->toContain('admin scope term')
        ->not->toContain('single occurrence term');

    // The threshold is a parameter, not a constant baked into the query.
    $unthresholded = array_map(
        static fn (PopularTerm $t): string => $t->term,
        $analytics->publicTerms(30, 100, 1),
    );
    expect($unthresholded)->toContain('single occurrence term')
        // …but the SCOPE filter is not negotiable at any threshold.
        ->not->toContain('admin scope term');
});

it('defines the public scope set as every non-admin scope, including global', function (): void {
    $public = SearchType::publicScopeValues();

    expect($public)->toContain('global')
        ->toContain('food')
        ->not->toContain('user');

    // `global` is what a DEFAULT public search records. Filtering on
    // documentTypeValues() alone would drop it and quietly empty out trending —
    // this assertion is what stops that regression.
    expect(SearchType::Global->documentTypeValues())->not->toContain('global');

    foreach (SearchType::cases() as $case) {
        expect(in_array($case->value, $public, true))->toBe(! $case->isAdminOnly());
    }
});

it('does not log the query strings it is protecting', function (): void {
    // The fix must not create a second exposure by writing terms to the log
    // pipeline. Nothing in the public analytics path emits the term.
    $source = file_get_contents(base_path('modules/Search/src/Application/Service/AutocompleteService.php'));
    $repo = file_get_contents(base_path('modules/Search/src/Infrastructure/Persistence/Eloquent/EloquentSearchAnalyticsRepository.php'));

    foreach ([$source, $repo] as $code) {
        expect($code)->not->toContain('Log::info')
            ->and($code)->not->toContain('Log::debug')
            ->and($code)->not->toContain('logger()->');
    }
});
