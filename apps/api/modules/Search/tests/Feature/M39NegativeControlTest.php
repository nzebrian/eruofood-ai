<?php

declare(strict_types=1);

use EruoFood\Search\Application\Service\AutocompleteService;
use EruoFood\Search\Domain\Access\SearchScopeGate;
use EruoFood\Search\Domain\Analytics\PopularTerm;
use EruoFood\Search\Domain\Analytics\SearchAnalyticsRepository;
use EruoFood\Search\Domain\Document\SearchIndexRepository;
use EruoFood\Search\Domain\Enum\SearchType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * M39 — controls on the M39-SEC-001 controls.
 *
 * `SearchAnalyticsPrivacyTest` passes. So would a version of it that asserted
 * nothing: the leak it guards was live on `ef6288d` for the whole of M38 and
 * nothing went red, because the only test touching `/trending` asserted
 * `assertOk()` and never looked at the body.
 *
 * Each control here RECONSTRUCTS the pre-M39 behaviour using the real
 * production collaborators and the real query path, and proves the security
 * assertion tells the two apart. The three mutations named in the M39 contract
 * are covered:
 *
 *   A. public scope filter removed          -> contrasted against `popular()`,
 *                                              which is that same aggregation
 *                                              without the scope constraint
 *   B. minimum-occurrence condition removed -> threshold driven to 1
 *   C. public consumer reads the admin method -> `adminBackedAutocomplete()`
 *
 * ## Limitation, stated plainly
 *
 * These reconstruct the defect at the seam: they build the collaborator the
 * old code used and show the assertion discriminates. They do NOT prove that an
 * arbitrary future edit to `EloquentSearchAnalyticsRepository` is caught by a
 * separate process. Out-of-process mutation of a copied checkout was tried in
 * M38 and could not be made sound — Composer resolves `__DIR__` through
 * symlinks, so a fixture that symlinks `vendor/` silently loads the real
 * classes and every control passes vacuously. The M39 file-level mutation runs
 * were therefore executed as an external harness against the production files
 * with sha256-verified restoration, and their results are recorded in the PR
 * rather than re-run by CI.
 */
function seedPrivacyFixture(): void
{
    $analytics = app(SearchAnalyticsRepository::class);

    // `user_id` is a uuid column: SQLite accepts any string, PostgreSQL does
    // not. See fixtureActor() in SearchAnalyticsPrivacyTest for why these are
    // hashed labels rather than readable ones.
    $admin = fixtureActor('admin-1');
    $userC = fixtureActor('user-c');
    $userA = fixtureActor('user-a');

    // Admin-only scope, most frequent term in the log — a filter that does
    // nothing cannot hide behind ordering.
    for ($i = 0; $i < 25; $i++) {
        $analytics->recordQuery('admin scope term', SearchType::User, 1, $admin);
    }
    // Public, over the threshold.
    for ($i = 0; $i < 5; $i++) {
        $analytics->recordQuery('public trend candidate', SearchType::Global, 1, $userC);
    }
    // Public, one occurrence only.
    $analytics->recordQuery('single occurrence term', SearchType::Food, 1, $userA);
}

beforeEach(function (): void {
    seedPrivacyFixture();
});

/** @return list<string> */
function terms(array $rows): array
{
    return array_map(static fn (PopularTerm $t): string => $t->term, $rows);
}

// =============================================================================
// A. The public scope filter
// =============================================================================

it('M39-SEC-001 · A · proves the scope filter is what excludes the admin term', function (): void {
    $analytics = app(SearchAnalyticsRepository::class);

    // FIXED: the public boundary excludes the admin-only scope …
    expect(terms($analytics->publicTerms(30, 100, 3)))->not->toContain('admin scope term');

    // … and the OLD path, reconstructed with the real repository: the same
    // aggregation WITHOUT the scope constraint returns it. If `publicTerms()`
    // stopped filtering by scope, its result would look like this one.
    expect(terms($analytics->popular(30, 100)))->toContain('admin scope term');

    // The two differ — which is the whole claim the security test makes.
    expect(terms($analytics->publicTerms(30, 100, 3)))
        ->not->toBe(terms($analytics->popular(30, 100)));
});

// =============================================================================
// B. The minimum-occurrence condition
// =============================================================================

it('M39-SEC-001 · B · proves the threshold is what suppresses the one-off term', function (): void {
    $analytics = app(SearchAnalyticsRepository::class);

    // FIXED, at the shipped default.
    expect(terms($analytics->publicTerms(30, 100, 3)))->not->toContain('single occurrence term');

    // Threshold driven to 1 — the condition disabled — and the same real query
    // returns it. This is mutation B expressed through the production seam.
    expect(terms($analytics->publicTerms(30, 100, 1)))->toContain('single occurrence term');

    // The scope filter is NOT weakened by lowering the threshold: the two
    // constraints are independent, so disabling one cannot silently disable
    // the other.
    expect(terms($analytics->publicTerms(30, 100, 1)))->not->toContain('admin scope term');
});

it('M39-SEC-001 · B · the shipped default is 3, not 1', function (): void {
    // A threshold of 1 is a no-op. If configuration ever ships that way the
    // suppression is off in production while every test above still passes at
    // its explicit argument — so the DEFAULT is asserted here.
    expect((int) config('search.public_term_min_occurrences'))->toBe(3)
        ->toBeGreaterThan(1);
});

// =============================================================================
// C. The public consumer reading the admin method
// =============================================================================

/** An AutocompleteService wired the pre-M39 way: analytics read unrestricted. */
function adminBackedAutocomplete(): AutocompleteService
{
    return new AutocompleteService(
        app(SearchIndexRepository::class),
        // A repository whose "public" boundary is the ADMIN aggregation — the
        // exact shape of the code before M39.
        new class (app(SearchAnalyticsRepository::class)) implements SearchAnalyticsRepository {
            public function __construct(private SearchAnalyticsRepository $inner)
            {
            }

            public function publicTerms(int $days, int $limit, int $minOccurrences): array
            {
                return $this->inner->popular($days, $limit);   // the regression
            }

            public function recordQuery(string $term, SearchType $type, int $resultCount, ?string $userId): string
            {
                return $this->inner->recordQuery($term, $type, $resultCount, $userId);
            }

            public function recordClick(string $queryId, string $documentId, int $position, bool $fromRecommendation): void
            {
                $this->inner->recordClick($queryId, $documentId, $position, $fromRecommendation);
            }

            public function popular(int $days, int $limit): array
            {
                return $this->inner->popular($days, $limit);
            }

            public function failed(int $days, int $limit): array
            {
                return $this->inner->failed($days, $limit);
            }

            public function trending(int $days, int $limit): array
            {
                return $this->inner->trending($days, $limit);
            }

            public function recentForUser(string $userId, int $limit): array
            {
                return $this->inner->recentForUser($userId, $limit);
            }

            public function metrics(int $days): \EruoFood\Search\Domain\Analytics\SearchMetrics
            {
                return $this->inner->metrics($days);
            }
        },
        8,
        7,
        10,
        new SearchScopeGate(),
        3,
    );
}

it('M39-SEC-001 · C · proves a consumer reading the admin method leaks', function (): void {
    // FIXED: the real service, resolved from the container.
    expect(app(AutocompleteService::class)->trending())
        ->not->toContain('admin scope term')
        ->toContain('public trend candidate');

    // REGRESSED: the same service class, same gate, same index — only the
    // analytics boundary swapped for the admin aggregation. It leaks.
    expect(adminBackedAutocomplete()->trending())->toContain('admin scope term');
});

it('M39-SEC-001 · C · the leak reaches suggestions too, not only trending', function (): void {
    expect(app(AutocompleteService::class)->suggestions('admin', SearchType::Global))
        ->not->toContain('admin scope term');

    expect(adminBackedAutocomplete()->suggestions('admin', SearchType::Global))
        ->toContain('admin scope term');
});

// =============================================================================
// Positive control
// =============================================================================

it('M39 · positive control — public discovery still works after the fix', function (): void {
    // The fix must not be "return nothing".
    $trending = $this->getJson('/api/v1/search/trending')->assertOk()->json('data.trending');
    expect($trending)->toContain('public trend candidate');

    $this->getJson('/api/v1/search/suggestions?q=public')->assertOk();
    $this->getJson('/api/v1/search/autocomplete?q=pub')->assertOk();
    $this->getJson('/api/v1/search?q=public')->assertOk();
});
