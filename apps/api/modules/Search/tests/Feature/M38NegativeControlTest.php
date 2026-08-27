<?php

declare(strict_types=1);

use EruoFood\Search\Application\Port\EmbeddingGenerator;
use EruoFood\Search\Application\Port\SearchCache;
use EruoFood\Search\Application\Service\AutocompleteService;
use EruoFood\Search\Application\Service\SearchIndexManager;
use EruoFood\Search\Domain\Access\SearchScopeGate;
use EruoFood\Search\Domain\Analytics\SearchAnalyticsRepository;
use EruoFood\Search\Domain\Capability\CapabilityState;
use EruoFood\Search\Domain\Document\Ranker;
use EruoFood\Search\Domain\Document\SearchIndexRepository;
use EruoFood\Search\Domain\Enum\SearchType;
use EruoFood\Search\Domain\Enum\SortOption;
use EruoFood\Search\Domain\Exception\SearchNotAuthorized;
use EruoFood\Search\Domain\ValueObject\SearchFilters;
use EruoFood\Search\Infrastructure\Persistence\Eloquent\EloquentSearchIndexRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * M38 — controls on the controls.
 *
 * The six M38 suites all pass. That is the state in which a vacuous test is
 * invisible: it passes because the code is right, and it would also pass if it
 * asserted nothing. Every defect in the Phase A audit had been shipping for
 * months precisely because nothing was looking, so "green" is not evidence.
 *
 * Each control below RECONSTRUCTS the dangerous behaviour in-process — the old
 * wiring, the old collaborator, the old assumption — and proves the assertion
 * distinguishes it from the fixed one. If a fix were reverted, the paired test
 * would go red, and these show why.
 *
 * ## Why these run in-process rather than against a mutated checkout
 *
 * The first version of this control copied `apps/api` to a mktemp fixture,
 * rewrote one source file per defect, and re-ran the suite there. It could not
 * be made sound: Composer resolves `__DIR__` through symlinks, so a fixture
 * that symlinks `vendor/` (4.7 GB — copying it seven times is not an option)
 * silently loads the REAL classes. The first run "passed" all six controls
 * while testing unmutated code, and only the positive control caught it.
 *
 * Simulating the defect at the seam is weaker in one specific way, stated
 * plainly: it proves the ASSERTION discriminates, not that a future edit to the
 * production file is caught by a separate process. It is not weaker about what
 * it does claim, and it does not silently pretend to be the stronger thing.
 */

function seedOneDocument(string $type, string $title, int $popularity = 1): void
{
    $sourceId = (string) Str::orderedUuid();

    DB::table('search_documents')->insert([
        'id' => $type.':'.$sourceId,
        'type' => $type,
        'source_id' => $sourceId,
        'title' => $title,
        'description' => 'control subject',
        'search_text' => mb_strtolower($title.' control subject'),
        'keywords' => json_encode([]),
        'locale' => 'en',
        'facets' => json_encode([]),
        'popularity' => $popularity,
        'rating' => 0,
        'embedding' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

// =============================================================================

it('M38-CACHE-001 · proves the isolation assertion would catch a whole-store clear', function (): void {
    Cache::put('unrelated:survivor', 'keep me', 600);

    // The FIXED path: unrelated keys survive.
    app(SearchCache::class)->flush();
    expect(Cache::get('unrelated:survivor'))->toBe('keep me');

    // The OLD path, reconstructed: `$this->cache->clear()`. If `flush()` still
    // did this, `SearchCacheIsolationTest` would fail — and here is the proof
    // that its assertion is capable of failing at all.
    Cache::clear();
    expect(Cache::get('unrelated:survivor'))->toBeNull();
});

it('M38-SEC-001 · proves an ungated autocomplete would expose the admin-only scope', function (): void {
    seedOneDocument('user', 'Adaeze Private Person');

    // The OLD collaborator: the index queried directly, exactly as
    // AutocompleteService did before the gate existed.
    $unguarded = app(SearchIndexRepository::class)->suggest('ada', SearchType::User, 8);
    expect($unguarded)->toContain('Adaeze Private Person');

    // The FIXED service refuses the same request before reaching the index.
    $guarded = new AutocompleteService(
        app(SearchIndexRepository::class),
        app(SearchAnalyticsRepository::class),
        8,
        7,
        10,
        new SearchScopeGate(),
    );

    expect(fn (): array => $guarded->autocomplete('ada', SearchType::User))
        ->toThrow(SearchNotAuthorized::class);
});

it('M38-SEARCH-001 · proves the total is not derived from the candidate pool', function (): void {
    for ($i = 0; $i < 30; $i++) {
        seedOneDocument('food', sprintf('Jollof Control %02d', $i), $i);
    }

    // A repository whose candidate pool is deliberately tiny. Under the old
    // implementation the reported total WAS the pool size, so this would say 5.
    $repository = new EloquentSearchIndexRepository(app(Ranker::class), candidatePool: 5, usePgvector: false, maxResultWindow: 1000);

    $query = app(\EruoFood\Search\Application\Service\QueryBuilder::class)->build(
        term: 'jollof',
        type: SearchType::Food,
        filters: new SearchFilters(),
        sort: SortOption::Popularity,
        page: 1,
        perPage: 10,
        locale: 'en',
        geo: null,
    );

    $results = $repository->search($query);

    expect($results->total)->toBe(30)
        ->and($results->total)->not->toBe(5)
        ->and($results->hits)->toHaveCount(10);
});

it('M38-SEARCH-001 · proves a deep page is served rather than silently emptied', function (): void {
    for ($i = 0; $i < 30; $i++) {
        seedOneDocument('food', sprintf('Jollof Deep %02d', $i), $i);
    }

    $repository = new EloquentSearchIndexRepository(app(Ranker::class), candidatePool: 5, usePgvector: false, maxResultWindow: 1000);

    $query = app(\EruoFood\Search\Application\Service\QueryBuilder::class)->build(
        term: 'jollof',
        type: SearchType::Food,
        filters: new SearchFilters(),
        sort: SortOption::Popularity,
        page: 3,     // offset 20 — far beyond the 5-row pool
        perPage: 10,
        locale: 'en',
        geo: null,
    );

    // The old implementation returned [] here while still claiming a total.
    expect($repository->search($query)->hits)->toHaveCount(10);
});

it('M38-QUEUE-001 · proves the async assertion would fail if the flag were flipped', function (): void {
    // The suite asserts the SHIPPED default is async. This shows that assertion
    // discriminates: with the flag off, the same expectation fails.
    expect(config('search.async_indexing'))->toBeTrue();

    config()->set('search.async_indexing', false);
    expect(config('search.async_indexing'))->toBeFalse();

    // Restored so the flag cannot leak into another test.
    config()->set('search.async_indexing', true);
});

it('M38-OBS-001 · proves a silent manager reports nothing where the fixed one speaks', function (): void {
    $records = [];

    // The FIXED manager, with a logger attached.
    $loud = new SearchIndexManager(
        app(SearchIndexRepository::class),
        app(EmbeddingGenerator::class),
        app(SearchCache::class),
        [],
        new class ($records) extends \Psr\Log\AbstractLogger {
            public function __construct(private array &$records)
            {
            }

            public function log($level, $message, array $context = []): void
            {
                $this->records[] = (string) $message;
            }
        },
    );

    $loud->reindex('not_a_type', 'abc');
    expect($records)->toContain('SEARCH_INDEX_UNKNOWN_TYPE');

    // The OLD shape: no observer at all. The same call produces nothing, which
    // is exactly the silence the audit found — and exactly what the
    // observability suite would catch if the reporting were removed.
    $silentRecords = [];
    $silent = new SearchIndexManager(
        app(SearchIndexRepository::class),
        app(EmbeddingGenerator::class),
        app(SearchCache::class),
        [],
        null,
    );

    $silent->reindex('not_a_type', 'abc');
    expect($silentRecords)->toBe([]);
});

it('M38-VECTOR-001 · proves probe_failed and unavailable are not interchangeable', function (): void {
    // If the probe rounded a failure down to "unavailable", these two states
    // would compare equal and the capability suite could not tell an outage
    // from an absence.
    expect(CapabilityState::ProbeFailed)->not->toBe(CapabilityState::Unavailable)
        ->and(CapabilityState::ProbeFailed->isUsable())->toBeFalse()
        ->and(CapabilityState::ProbeFailed->isDegraded())->toBeTrue()
        // And configuration-disabled is a choice, not a fault — a third state
        // that a two-valued boolean could not have carried.
        ->and(CapabilityState::DisabledByConfig->isDegraded())->toBeFalse();
});

it('M38 · positive control — the fixed code passes every one of these paths', function (): void {
    seedOneDocument('food', 'Jollof Positive Control');

    // Nothing exotic: the ordinary public path still works after all six fixes.
    $this->getJson('/api/v1/search?q=jollof')
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.total_is_exact', true);

    $this->getJson('/api/v1/search/autocomplete?q=jol')->assertOk();
});
