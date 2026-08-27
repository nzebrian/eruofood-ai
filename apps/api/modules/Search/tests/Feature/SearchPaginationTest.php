<?php

declare(strict_types=1);

use EruoFood\Search\Domain\Enum\SearchType;
use EruoFood\Search\Domain\Enum\SortOption;
use EruoFood\Search\Domain\ValueObject\SearchFilters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * M38-SEARCH-001 — totals and deep pages must be true.
 *
 * The old repository fetched a fixed 200-row candidate pool, ranked it in PHP,
 * and then reported `count($sorted)` as the total with
 * `array_slice($sorted, $offset, $perPage)` as the page. Two lies followed from
 * that: a corpus larger than the pool reported a total capped at 200, and every
 * page past offset 200 came back EMPTY while the response still advertised more
 * results.
 *
 * Every test here is sized to exceed the 200-row pool on purpose. Each one
 * fails against the old implementation.
 */

/** Index `$count` documents directly, bypassing the event path for speed. */
function seedDocuments(int $count, string $type = 'food'): void
{
    $rows = [];
    $now = now();

    for ($i = 0; $i < $count; $i++) {
        $sourceId = (string) Str::orderedUuid();
        $rows[] = [
            'id' => $type.':'.$sourceId,
            'type' => $type,
            'source_id' => $sourceId,
            'title' => sprintf('Jollof Batch %04d', $i),
            'description' => 'A bulk indexed jollof document',
            'search_text' => sprintf('jollof batch %04d bulk indexed document', $i),
            'keywords' => json_encode(['jollof']),
            'locale' => 'en',
            'facets' => json_encode(['popularity' => $i, 'rating' => 0]),
            'region' => $i % 2 === 0 ? 'South West' : 'North',
            'popularity' => $i,
            'rating' => 0,
            'embedding' => json_encode([]),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    foreach (array_chunk($rows, 100) as $chunk) {
        DB::table('search_documents')->insert($chunk);
    }
}

it('reports a total larger than the old 200-row candidate pool', function (): void {
    seedDocuments(260);

    // The old implementation capped this at the pool size (200).
    $this->getJson('/api/v1/search?q=jollof&per_page=10')
        ->assertOk()
        ->assertJsonPath('data.total', 260)
        ->assertJsonPath('data.total_is_exact', true);
});

it('serves a page beyond the old candidate pool instead of silently returning nothing', function (): void {
    seedDocuments(260);

    // offset 220 — past the old 200-row pool, which returned an empty page.
    $response = $this->getJson('/api/v1/search?q=jollof&per_page=10&page=23&sort=popularity')
        ->assertOk()
        ->assertJsonPath('data.total', 260);

    expect($response->json('data.hits'))->toHaveCount(10);
});

it('paginates SQL-ordered sorts exactly, with no overlap between pages', function (): void {
    seedDocuments(260);

    $first = $this->getJson('/api/v1/search?q=jollof&per_page=25&page=1&sort=popularity')->assertOk();
    $second = $this->getJson('/api/v1/search?q=jollof&per_page=25&page=2&sort=popularity')->assertOk();

    $idsA = array_column(array_column($first->json('data.hits'), 'document'), 'id');
    $idsB = array_column(array_column($second->json('data.hits'), 'document'), 'id');

    expect($idsA)->toHaveCount(25)
        ->and($idsB)->toHaveCount(25)
        ->and(array_intersect($idsA, $idsB))->toBe([]);
});

it('returns a correct final partial page', function (): void {
    seedDocuments(205);

    // 205 documents at 100/page: page 3 holds the remaining 5.
    $response = $this->getJson('/api/v1/search?q=jollof&per_page=100&page=3&sort=popularity')
        ->assertOk()
        ->assertJsonPath('data.total', 205);

    expect($response->json('data.hits'))->toHaveCount(5);
});

it('returns an empty page beyond the true end while still reporting the true total', function (): void {
    seedDocuments(205);

    $response = $this->getJson('/api/v1/search?q=jollof&per_page=100&page=4&sort=popularity')
        ->assertOk()
        ->assertJsonPath('data.total', 205);

    // Empty because there genuinely is nothing there — and the total still
    // tells the truth, which is the difference from the old behaviour.
    expect($response->json('data.hits'))->toBe([]);
});

it('keeps ordering stable across identical sort keys', function (): void {
    // Every document shares rating 0, so only the tiebreak separates them.
    seedDocuments(260);

    $a = $this->getJson('/api/v1/search?q=jollof&per_page=20&page=5&sort=rating')->assertOk();
    $b = $this->getJson('/api/v1/search?q=jollof&per_page=20&page=5&sort=rating')->assertOk();

    expect($a->json('data.hits.0.document.id'))->toBe($b->json('data.hits.0.document.id'));
});

it('combines a filter with pagination and counts only the filtered set', function (): void {
    seedDocuments(260);

    // Half the corpus is South West.
    $this->getJson('/api/v1/search?q=jollof&region=South+West&per_page=10&sort=popularity')
        ->assertOk()
        ->assertJsonPath('data.total', 130)
        ->assertJsonPath('data.total_is_exact', true);
});

it('refuses a page beyond the ranking window instead of returning an empty page', function (): void {
    seedDocuments(10);

    // Relevance is ranked in PHP, so it has a bounded window
    // (search.max_result_window). Asking past it is an explicit refusal.
    config()->set('search.max_result_window', 50);

    $query = app(\EruoFood\Search\Application\Service\QueryBuilder::class)->build(
        term: 'jollof',
        type: SearchType::Food,
        filters: new SearchFilters(),
        sort: SortOption::Relevance,
        page: 20,          // offset 200
        perPage: 10,
        locale: 'en',
        geo: null,
    );

    $repository = app(\EruoFood\Search\Domain\Document\SearchIndexRepository::class);

    expect(fn (): mixed => $repository->search($query))
        ->toThrow(\EruoFood\Search\Domain\Exception\SearchPaginationTooDeep::class);
});

// =============================================================================
// The window BOUNDARY (M38-SEARCH-001, remediation).
//
// The first fix tested only `offset >= max_result_window`. A page that STARTED
// inside the window but ended outside it was accepted, clamped, and answered
// with a short page — offset 995 with per_page 20 against a 1000-row window
// returned 5 hits while `total` reported the full match count. That is the same
// silent lie as the original defect, one page further in.
//
// The rule is now: the whole page must fit. `offset + per_page <= window`.
// =============================================================================

/** Build a relevance-sorted query (the PHP-ranked path) at an exact position. */
function windowQuery(int $page, int $perPage): \EruoFood\Search\Domain\ValueObject\SearchQuery
{
    return app(\EruoFood\Search\Application\Service\QueryBuilder::class)->build(
        term: 'jollof',
        type: SearchType::Food,
        filters: new SearchFilters(),
        sort: SortOption::Relevance,   // ranked in PHP, so the window applies
        page: $page,
        perPage: $perPage,
        locale: 'en',
        geo: null,
    );
}

it('accepts the last page that fits entirely inside the ranking window', function (): void {
    seedDocuments(10);
    config()->set('search.max_result_window', 1000);

    // offset 980 + per_page 20 = 1000, exactly the window. Accepted.
    $query = windowQuery(page: 50, perPage: 20);
    expect($query->offset())->toBe(980);

    $repository = app(\EruoFood\Search\Domain\Document\SearchIndexRepository::class);

    expect(fn (): mixed => $repository->search($query))
        ->not->toThrow(\EruoFood\Search\Domain\Exception\SearchPaginationTooDeep::class);
});

it('refuses the next page, whose end falls outside the window', function (): void {
    seedDocuments(10);
    config()->set('search.max_result_window', 1000);

    // offset 1000 + per_page 20 = 1020. Refused.
    $query = windowQuery(page: 51, perPage: 20);
    expect($query->offset())->toBe(1000);

    $repository = app(\EruoFood\Search\Domain\Document\SearchIndexRepository::class);

    expect(fn (): mixed => $repository->search($query))
        ->toThrow(\EruoFood\Search\Domain\Exception\SearchPaginationTooDeep::class);
});

it('refuses a page that STRADDLES the window rather than clamping it short', function (): void {
    // This is the reported case, expressed at a page boundary: the offset is
    // INSIDE the window (980 < 995) but the page runs past it (980 + 20 = 1000).
    // The previous rule accepted this, clamped the window to 995 and returned a
    // short page. There is nothing in such a response to tell a client that the
    // page was truncated rather than genuinely final.
    seedDocuments(10);
    config()->set('search.max_result_window', 995);

    $query = windowQuery(page: 50, perPage: 20);
    expect($query->offset())->toBe(980)
        ->and($query->offset())->toBeLessThan(995)          // the old rule let it through
        ->and($query->offset() + $query->perPage)->toBeGreaterThan(995);

    $repository = app(\EruoFood\Search\Domain\Document\SearchIndexRepository::class);

    expect(fn (): mixed => $repository->search($query))
        ->toThrow(\EruoFood\Search\Domain\Exception\SearchPaginationTooDeep::class);
});

it('tells the caller where the boundary actually is', function (): void {
    seedDocuments(10);
    config()->set('search.max_result_window', 1000);

    $repository = app(\EruoFood\Search\Domain\Document\SearchIndexRepository::class);

    try {
        $repository->search(windowQuery(page: 51, perPage: 20));
        $this->fail('expected SearchPaginationTooDeep');
    } catch (\EruoFood\Search\Domain\Exception\SearchPaginationTooDeep $e) {
        // An actionable refusal names the last usable offset at this page size,
        // so a client can correct rather than guess.
        expect($e->errorCode())->toBe('SEARCH_PAGINATION_TOO_DEEP')
            ->and($e->getMessage())->toContain('980')
            ->and($e->getMessage())->toContain('1000');
    }
});

it('leaves SQL-ordered sorts unbounded — they never needed a window', function (): void {
    seedDocuments(260);
    config()->set('search.max_result_window', 50);

    // Popularity is ordered by PostgreSQL with LIMIT/OFFSET, so the PHP window
    // does not apply and a deep page is exact rather than refused.
    $this->getJson('/api/v1/search?q=jollof&per_page=10&page=25&sort=popularity')
        ->assertOk()
        ->assertJsonPath('data.total', 260);
});
