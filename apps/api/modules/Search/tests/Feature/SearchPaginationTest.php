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
