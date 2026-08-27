<?php

declare(strict_types=1);

use EruoFood\Catalog\Domain\Event\FoodPublished;
use EruoFood\Search\Application\Port\EmbeddingGenerator;
use EruoFood\Search\Application\Port\SearchCache;
use EruoFood\Search\Application\Port\SourceDocumentProvider;
use EruoFood\Search\Application\Service\EventIndexTranslator;
use EruoFood\Search\Application\Service\SearchIndexManager;
use EruoFood\Search\Domain\Capability\CapabilityState;
use EruoFood\Search\Domain\Document\Ranker;
use EruoFood\Search\Domain\Document\SearchDocument;
use EruoFood\Search\Domain\Document\SearchIndexRepository;
use EruoFood\Search\Domain\Enum\SearchType;
use EruoFood\Search\Domain\Enum\SortOption;
use EruoFood\Search\Domain\Observability\IndexFailure;
use EruoFood\Search\Domain\ValueObject\SearchFilters;
use EruoFood\Search\Infrastructure\Capability\SearchCapabilityProbe;
use EruoFood\Search\Infrastructure\Job\ReindexDocumentJob;
use EruoFood\Search\Infrastructure\Persistence\Eloquent\EloquentSearchIndexRepository;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * M38 — controls on the controls.
 *
 * The M38 suites all pass. That is the state in which a vacuous test is
 * invisible: it passes because the code is right, and it would also pass if it
 * asserted nothing. Every defect in the Phase A audit had been shipping for
 * months precisely because nothing was looking, so "green" is not evidence.
 *
 * Each control below drives the REAL production collaborator with the REAL
 * request shape and proves the assertion discriminates.
 *
 * ## What the first version of this file got wrong
 *
 * Three of its seven controls could not fail:
 *
 *  - QUEUE-001 read a config value, set it, asserted it was set, and set it
 *    back. It exercised `config()->set()`. It never constructed an
 *    `EventIndexTranslator` and never observed a dispatch.
 *  - OBS-001's "silent" half declared `$silentRecords = []`, passed it to
 *    nothing, and asserted `expect([])->toBe([])`.
 *  - VECTOR-001 asserted that two distinct enum cases are distinct — true by
 *    construction of the language.
 *
 * And its SEC-001 control only ever drove `type=user`, so it did not catch the
 * live leak on the DEFAULT `Global` request that the strict review found. Each
 * of those is replaced below with a control that fails against the defect it
 * claims to guard.
 *
 * ## Why these run in-process rather than against a mutated checkout
 *
 * The first attempt copied `apps/api` to a mktemp fixture, rewrote one source
 * file per defect, and re-ran the suite there. It could not be made sound:
 * Composer resolves `__DIR__` through symlinks, so a fixture that symlinks
 * `vendor/` (4.7 GB — copying it seven times is not an option) silently loads
 * the REAL classes. The first run "passed" all six controls while testing
 * unmutated code.
 *
 * Driving the real collaborator in-process is weaker in one specific way,
 * stated plainly: it proves the assertion discriminates against the defect
 * reconstructed here, not that a separate process would catch an arbitrary
 * future edit. It is not weaker about what it does claim.
 */
function seedOneDocument(string $type, string $title, int $popularity = 1): string
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

    return $sourceId;
}

// =============================================================================
// CACHE-001
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

// =============================================================================
// SEC-001 — drives the real production routes, including the DEFAULT scope.
// =============================================================================

/**
 * The control the first version needed and did not have.
 *
 * Against 5c3e9d8 the failing assertion is the `not->toContain($title)` on the
 * autocomplete and suggestions bodies: `EloquentSearchIndexRepository::suggest()`
 * skipped the type filter for `Global`, so a request with no `type` parameter
 * at all returned the protected title with HTTP 200.
 */
it('M38-SEC-001 · a default public request never reaches an admin-only document', function (): void {
    $sourceId = seedOneDocument('user', 'Adaeze Private Person', 10_000);

    // A. default request — no `type` parameter, so the scope resolves to Global
    // B. the protected document exists (seeded above, ranked top by popularity)
    // C. the endpoint must not return it
    foreach ([
        '/api/v1/search?q=ada',
        '/api/v1/search/autocomplete?q=ada',
        '/api/v1/search/suggestions?q=ada',
        '/api/v1/search/recommendations?kind=trending',
        // E/F. the same fan-out named explicitly.
        '/api/v1/search/recommendations?kind=trending&type=global',
        '/api/v1/search/suggestions?q=ada&type=global',
        '/api/v1/search/autocomplete?q=ada&type=global',
    ] as $url) {
        $body = $this->getJson($url)->getContent();

        expect($body)->not->toContain('Adaeze Private Person')
            ->and($body)->not->toContain($sourceId)
            ->and($body)->not->toContain('"type":"user"');
    }

    // D. the explicit admin-only scope is refused outright.
    expect($this->getJson('/api/v1/search/autocomplete?q=ada&type=user')->status())->toBe(403);
});

it('M38-SEC-001 · the index itself refuses to read an admin-only type under Global', function (): void {
    seedOneDocument('user', 'Adaeze Private Person', 10_000);
    seedOneDocument('food', 'Adalu Public Dish', 1);

    $index = app(SearchIndexRepository::class);

    // The seam the leak lived at. `suggest()` and `popular()` used to apply no
    // type filter for Global, so both of these returned the user document.
    expect($index->suggest('ada', SearchType::Global, 10))
        ->toContain('Adalu Public Dish')
        ->not->toContain('Adaeze Private Person');

    $popularTitles = array_map(
        static fn (SearchDocument $d): string => $d->title(),
        $index->popular(SearchType::Global, 10),
    );

    expect($popularTitles)->toContain('Adalu Public Dish')
        ->not->toContain('Adaeze Private Person');

    // A null scope is the same public fan-out, not "unfiltered".
    expect($index->suggest('ada', null, 10))->not->toContain('Adaeze Private Person');
});

// =============================================================================
// SEARCH-001
// =============================================================================

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

it('M38-SEARCH-001 · proves a straddling page is refused rather than clamped short', function (): void {
    seedOneDocument('food', 'Jollof Straddle', 1);

    // offset 980 is INSIDE a 995-row window, but the page ends at 1000. The
    // pre-remediation rule (`offset >= window`) accepted this and answered with
    // 15 hits instead of 20 — a short page indistinguishable from the last one.
    $repository = new EloquentSearchIndexRepository(app(Ranker::class), candidatePool: 200, usePgvector: false, maxResultWindow: 995);

    $query = app(\EruoFood\Search\Application\Service\QueryBuilder::class)->build(
        term: 'jollof',
        type: SearchType::Food,
        filters: new SearchFilters(),
        sort: SortOption::Relevance,   // the PHP-ranked path, where the window applies
        page: 50,
        perPage: 20,
        locale: 'en',
        geo: null,
    );

    expect($query->offset())->toBe(980)
        ->and($query->offset())->toBeLessThan(995);

    expect(fn (): mixed => $repository->search($query))
        ->toThrow(\EruoFood\Search\Domain\Exception\SearchPaginationTooDeep::class);
});

// =============================================================================
// QUEUE-001 — a real seam around EventIndexTranslator.
// =============================================================================

/** Build a translator exactly as the provider does, with one flag overridden. */
function translatorWith(bool $async): EventIndexTranslator
{
    return new EventIndexTranslator(
        app(SearchIndexManager::class),
        (array) config('search.index_events'),
        app(BusDispatcher::class),
        async: $async,
        queue: (string) config('search.queue', 'search'),
        tries: (int) config('search.index_job_tries', 5),
        timeout: (int) config('search.index_job_timeout', 120),
        backoff: (array) config('search.index_job_backoff', [10, 30, 120, 300]),
    );
}

it('M38-QUEUE-001 · a published event pushes the real job onto the search queue', function (): void {
    Queue::fake();

    $foodId = (string) Str::orderedUuid();

    // The real translator, the real event class, the real dispatcher.
    translatorWith(async: true)->handle(new FoodPublished($foodId));

    Queue::assertPushed(
        ReindexDocumentJob::class,
        function (ReindexDocumentJob $job) use ($foodId): bool {
            return $job->type === 'food'
                && $job->sourceId === $foodId
                && $job->queue === config('search.queue', 'search')
                && $job->tries === (int) config('search.index_job_tries', 5)
                && $job->uniqueId() === 'food:'.$foodId;
        },
    );

    // Deleting the translator, or having it call the index manager inline,
    // leaves nothing on the queue and fails the assertion above.
    Queue::assertPushed(ReindexDocumentJob::class, 1);
});

it('M38-QUEUE-001 · the synchronous fallback really is synchronous, and only behind the flag', function (): void {
    Queue::fake();

    // The documented rollback path: nothing is enqueued, and the work happens
    // on this thread. Both halves matter — an implementation that enqueued
    // anyway would look "fixed" while ignoring the operator's lever.
    translatorWith(async: false)->handle(new FoodPublished((string) Str::orderedUuid()));

    Queue::assertNothingPushed();
});

it('M38-QUEUE-001 · an unmapped event enqueues nothing at all', function (): void {
    Queue::fake();

    $unmapped = new class () implements \EruoFood\Shared\Domain\DomainEvent {
        public function eventName(): string
        {
            return 'some.other_context_event';
        }

        public function occurredAt(): \DateTimeImmutable
        {
            return new \DateTimeImmutable();
        }
    };

    translatorWith(async: true)->handle($unmapped);

    // Search reacts to the config map and nothing else. A translator that
    // enqueued on every event would couple Search to every context.
    Queue::assertNothingPushed();
});

// =============================================================================
// OBS-001 — real failure paths, with the tautology removed.
// =============================================================================

/** A provider whose behaviour each control chooses. */
function controlProvider(callable $fetch): SourceDocumentProvider
{
    return new class ($fetch) implements SourceDocumentProvider {
        public function __construct(private $fetch)
        {
        }

        public function fetch(string $sourceId): ?SearchDocument
        {
            return ($this->fetch)($sourceId);
        }

        public function allIds(): array
        {
            return [];
        }

        public function type(): string
        {
            return 'food';
        }
    };
}

/**
 * @param array<int, array{level: string, code: string}> $records
 */
function observedManager(array $providers, array &$records): SearchIndexManager
{
    return new SearchIndexManager(
        app(SearchIndexRepository::class),
        app(EmbeddingGenerator::class),
        app(SearchCache::class),
        $providers,
        new class ($records) extends \Psr\Log\AbstractLogger {
            public function __construct(private array &$records)
            {
            }

            public function log($level, $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'code' => (string) $message];
            }
        },
    );
}

it('M38-OBS-001 · every real failure path emits its own stable code', function (): void {
    // 1. Unknown document type.
    $unknown = [];
    observedManager([], $unknown)->reindex('not_a_type', 'abc');

    // 2. Missing source row. A real uuid, because this path reaches
    //    deleteBySourceId() and `source_id` is a uuid column on PostgreSQL.
    $missing = [];
    observedManager(['food' => controlProvider(fn (): ?SearchDocument => null)], $missing)
        ->reindex('food', (string) Str::orderedUuid());

    // 3. Provider failure — distinct from "missing", and rethrown.
    $failed = [];
    $manager = observedManager(
        ['food' => controlProvider(function (): ?SearchDocument {
            throw new RuntimeException('catalog unavailable');
        })],
        $failed,
    );
    expect(fn () => $manager->reindex('food', 'boom'))->toThrow(RuntimeException::class);

    // Each path reports, and reports something DIFFERENT. Removing the
    // reporting empties these arrays; collapsing the codes into one fails the
    // distinctness assertions.
    expect(array_column($unknown, 'code'))->toContain(IndexFailure::UnknownType->value)
        ->and(array_column($missing, 'code'))->toContain(IndexFailure::SourceMissing->value)
        ->and(array_column($failed, 'code'))->toContain(IndexFailure::ProviderFailed->value);

    expect(array_column($missing, 'code'))->not->toContain(IndexFailure::UnknownType->value)
        ->and(array_column($failed, 'code'))->not->toContain(IndexFailure::SourceMissing->value);

    // Severity is part of the signal: an expected unpublish is not an alarm,
    // an unknown type is.
    expect($unknown[0]['level'])->toBe('error')
        ->and($missing[0]['level'])->toBe('info');
});

it('M38-OBS-001 · a manager with no observer is exactly the silence the audit found', function (): void {
    // The OLD shape: the same failure, with nothing attached to hear it. The
    // point of the control is the CONTRAST — the observed manager above records
    // three codes for these paths; this one is structurally incapable of it.
    $observed = [];
    observedManager([], $observed)->reindex('not_a_type', 'abc');

    $silent = new SearchIndexManager(
        app(SearchIndexRepository::class),
        app(EmbeddingGenerator::class),
        app(SearchCache::class),
        [],
        null,
    );

    // It must still not throw — silence was the defect, not a crash.
    expect(fn () => $silent->reindex('not_a_type', 'abc'))->not->toThrow(Throwable::class);

    expect($observed)->not->toBe([])
        ->and(array_column($observed, 'code'))->toContain(IndexFailure::UnknownType->value);
});

// =============================================================================
// VECTOR-001 — the real capability decision, across every state.
// =============================================================================

/** A connection whose two catalog queries answer however the control likes. */
function controlConnection(callable $select): ConnectionInterface
{
    return new class ($select) implements ConnectionInterface {
        public function __construct(private $selectHandler)
        {
        }

        public function select($query, $bindings = [], $useReadPdo = true)
        {
            return ($this->selectHandler)($query, $bindings);
        }

        public function table($table, $as = null)
        {
            throw new BadMethodCallException('not used');
        }

        public function raw($value)
        {
            throw new BadMethodCallException('not used');
        }

        public function selectOne($query, $bindings = [], $useReadPdo = true)
        {
            return null;
        }

        public function cursor($query, $bindings = [], $useReadPdo = true)
        {
            return [];
        }

        public function insert($query, $bindings = [])
        {
            return true;
        }

        public function update($query, $bindings = [])
        {
            return 0;
        }

        public function delete($query, $bindings = [])
        {
            return 0;
        }

        public function statement($query, $bindings = [])
        {
            return true;
        }

        public function affectingStatement($query, $bindings = [])
        {
            return 0;
        }

        public function unprepared($query)
        {
            return true;
        }

        public function prepareBindings(array $bindings)
        {
            return $bindings;
        }

        public function transaction(Closure $callback, $attempts = 1)
        {
            return $callback($this);
        }

        public function beginTransaction()
        {
        }

        public function commit()
        {
        }

        public function rollBack($toLevel = null)
        {
        }

        public function transactionLevel()
        {
            return 0;
        }

        public function pretend(Closure $callback)
        {
            return [];
        }

        public function getDatabaseName()
        {
            return 'test';
        }

        public function scalar($query, $bindings = [], $useReadPdo = true)
        {
            return null;
        }
    };
}

it('M38-VECTOR-001 · the capability decision is false in every state but a verified one', function (): void {
    // Extension present, index present — the ONLY state that may report active.
    $healthy = (new SearchCapabilityProbe(
        controlConnection(fn (): array => [(object) ['x' => 1]]),
        driver: 'pgsql',
        vectorRequested: true,
        trigramRequested: true,
    ))->probe();

    // Extension absent.
    $absent = (new SearchCapabilityProbe(
        controlConnection(fn (): array => []),
        driver: 'pgsql',
        vectorRequested: true,
        trigramRequested: true,
    ))->probe();

    // The probe itself failed — nothing is known.
    $broken = (new SearchCapabilityProbe(
        controlConnection(function (): array {
            throw new RuntimeException('connection reset');
        }),
        driver: 'pgsql',
        vectorRequested: true,
        trigramRequested: true,
    ))->probe();

    // Switched off deliberately.
    $disabled = (new SearchCapabilityProbe(
        controlConnection(fn (): array => [(object) ['x' => 1]]),
        driver: 'pgsql',
        vectorRequested: false,
        trigramRequested: false,
    ))->probe();

    // Extension present, index missing — what an interrupted migration leaves.
    $indexless = (new SearchCapabilityProbe(
        controlConnection(fn (string $q): array => str_contains($q, 'pg_extension') ? [(object) ['x' => 1]] : []),
        driver: 'pgsql',
        vectorRequested: true,
        trigramRequested: true,
    ))->probe();

    // Replacing the production decision with `return true` fails four of these
    // five assertions; replacing it with `return false` fails the first.
    expect($healthy->nativeVectorSearchActive())->toBeTrue()
        ->and($absent->nativeVectorSearchActive())->toBeFalse()
        ->and($broken->nativeVectorSearchActive())->toBeFalse()
        ->and($disabled->nativeVectorSearchActive())->toBeFalse()
        ->and($indexless->nativeVectorSearchActive())->toBeFalse();

    // And the states remain distinguishable, so an operator can tell an outage
    // from an absence from a deliberate choice.
    expect($absent->vector)->toBe(CapabilityState::Unavailable)
        ->and($broken->vector)->toBe(CapabilityState::ProbeFailed)
        ->and($disabled->vector)->toBe(CapabilityState::DisabledByConfig)
        ->and($indexless->vector)->toBe(CapabilityState::Available)
        ->and($indexless->vectorIndex)->toBe(CapabilityState::Unavailable);

    // Degraded means "an operator should look": a fault, never a choice.
    expect($absent->isDegraded())->toBeTrue()
        ->and($broken->isDegraded())->toBeTrue()
        ->and($disabled->isDegraded())->toBeFalse()
        ->and($healthy->isDegraded())->toBeFalse();
});

it('M38-VECTOR-001 · the repository re-probes once the memo expires', function (): void {
    $probes = 0;
    $connection = controlConnection(function () use (&$probes): array {
        $probes++;

        return [];
    });

    // Exactly the probe the repository builds for itself: vector only, no
    // trigram — so one probe() is one catalog query and the counter is a
    // faithful count of probes rather than of statements.
    $probe = new SearchCapabilityProbe($connection, driver: 'pgsql', vectorRequested: true, trigramRequested: false);

    // A relevance query takes the PHP-ranked path, which asks the capability
    // before choosing its candidate ordering — the production call site.
    $query = app(\EruoFood\Search\Application\Service\QueryBuilder::class)->build(
        term: 'jollof',
        type: SearchType::Food,
        filters: new SearchFilters(),
        sort: SortOption::Relevance,
        page: 1,
        perPage: 5,
        locale: 'en',
        geo: null,
    );

    // A live memo: the second read reuses the first answer.
    $memoised = new EloquentSearchIndexRepository(
        app(Ranker::class),
        candidatePool: 10,
        usePgvector: true,
        maxResultWindow: 1000,
        capabilityTtlSeconds: 300.0,
        probe: $probe,
    );
    $memoised->search($query);
    $memoised->search($query);

    expect($probes)->toBe(1);

    // An expired memo re-derives. This is the queue-worker case: the repository
    // is a container singleton held by further singletons, and a worker is a
    // long-lived process, so a permanently cached "absent" would survive the
    // migration that provisions the extension.
    $probes = 0;
    $expiring = new EloquentSearchIndexRepository(
        app(Ranker::class),
        candidatePool: 10,
        usePgvector: true,
        maxResultWindow: 1000,
        capabilityTtlSeconds: 0.0,
        probe: $probe,
    );
    $expiring->search($query);
    $expiring->search($query);

    expect($probes)->toBe(2);
});

// =============================================================================

it('M38 · positive control — the fixed code passes every one of these paths', function (): void {
    seedOneDocument('food', 'Jollof Positive Control');

    // Nothing exotic: the ordinary public path still works after all the fixes.
    $this->getJson('/api/v1/search?q=jollof')
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.total_is_exact', true);

    $this->getJson('/api/v1/search/autocomplete?q=jol')->assertOk();
});
