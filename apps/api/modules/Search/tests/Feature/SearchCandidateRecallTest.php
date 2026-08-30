<?php

declare(strict_types=1);

use EruoFood\Catalog\Domain\Event\FoodPublished;
use EruoFood\Shared\Domain\EventBus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Search must not answer "nothing found" to a query that has a result.
 *
 * ## The defect this pins
 *
 * `search()` counts the match set with one query and fetches the candidates to
 * rank with another. The count carries no `ORDER BY`; the fetch ordered by
 * `embedding_vec <=> …`. Where pgvector is installed that ordering is served by
 * an **ivfflat index**, which is approximate: it probes one list out of `lists`
 * and the `WHERE` clause is then applied to whatever that probe happened to
 * yield. With a selective filter the two disagree completely. EXPLAIN over a
 * 121-document index, searching a phrase that matches exactly one document:
 *
 *     Index Scan using search_documents_embedding_vec_idx  (actual rows=0)
 *       Filter: (search_text ~~ '%jollof rice%')
 *       Rows Removed by Filter: 39
 *
 * `total` said 1. `hits` was empty. The customer saw no results for a dish that
 * is in the catalogue.
 *
 * ## Why it hid for so long
 *
 * It is not intermittent — it is **conditional**, and the condition is the
 * database. A developer's PostgreSQL without the `vector` extension takes the
 * portable ordering, fetches everything and passes every time; CI runs
 * `pgvector/pgvector:pg16` and fails every time. Read as "flaky" it looks like
 * test noise. It is a production correctness bug that only production-shaped
 * databases can see, which is the worst place for one to live.
 *
 * These tests assert the contract rather than the mechanism: a non-empty
 * `total` must produce a non-empty first page. That holds on both database
 * paths, so it keeps its meaning wherever it runs.
 */

/** Index a food through its domain event, exactly as the app does. */
function recallFood(string $name, string $description): string
{
    $id = (string) Str::orderedUuid();

    DB::table('catalog_foods')->insert([
        'id' => $id,
        'name' => $name,
        'slug' => Str::slug($name).'-'.substr($id, 0, 8),
        'description' => $description,
        'category_id' => (string) Str::orderedUuid(),
        'region' => 'South West',
        'states' => json_encode([]),
        'local_names' => json_encode([]),
        'nutrition' => json_encode(['calories' => 500]),
        'images' => json_encode([]),
        'tags' => json_encode([]),
        'status' => 'published',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    app(EventBus::class)->publish(new FoodPublished($id));

    return $id;
}

/** A haystack large enough that a single-list ANN probe cannot cover it. */
function seedHaystack(int $count = 120): void
{
    for ($i = 0; $i < $count; $i++) {
        recallFood("Dish {$i} rice", "A tasty rice dish number {$i}");
    }
}

it('returns the one matching document rather than an empty page', function (): void {
    // The exact shape that failed: a needle inside a haystack big enough for
    // the approximate index to miss it.
    seedHaystack();
    recallFood('Signature Jollof', 'The definitive smoky party jollof rice');

    $data = $this->getJson('/api/v1/search?'.http_build_query(['q' => 'jollof rice', 'per_page' => 10]))
        ->assertOk()
        ->json('data');

    expect($data['total'])->toBe(1)
        ->and($data['hits'])->toHaveCount(1)
        ->and($data['hits'][0]['document']['title'])->toBe('Signature Jollof');
});

it('never reports a total it cannot show a first page for', function (): void {
    // The invariant, stated once and for all: counting and fetching must agree.
    // A page that is empty while `total` is positive is the silent lie — the
    // caller is told there are results and shown none, with no error to notice.
    seedHaystack();
    recallFood('Signature Jollof', 'The definitive smoky party jollof rice');
    recallFood('Party Jollof Supreme', 'Another smoky party jollof rice');

    foreach (['jollof rice', 'smoky party', 'rice', 'definitive'] as $term) {
        $data = $this->getJson('/api/v1/search?'.http_build_query(['q' => $term, 'per_page' => 10]))
            ->assertOk()
            ->json('data');

        if ($data['total'] > 0) {
            expect($data['hits'])->not->toBeEmpty("query '{$term}' reported {$data['total']} results and returned none");
        }
    }
});

it('returns the same first result for the same query twice', function (): void {
    // Determinism. Popularity alone ties every never-viewed document at 0, and
    // PostgreSQL may return tied rows in any order; the candidate window would
    // then be a different set of rows run to run, and so would the answer.
    seedHaystack(30);
    recallFood('Signature Jollof', 'The definitive smoky party jollof rice');

    $first = $this->getJson('/api/v1/search?'.http_build_query(['q' => 'rice', 'per_page' => 5]))
        ->assertOk()->json('data.hits');

    // A second identical query, past the result cache, must agree with it.
    app(EruoFood\Search\Application\Port\SearchCache::class)->flush();

    $second = $this->getJson('/api/v1/search?'.http_build_query(['q' => 'rice', 'per_page' => 5]))
        ->assertOk()->json('data.hits');

    expect(array_column(array_column($second, 'document'), 'title'))
        ->toBe(array_column(array_column($first, 'document'), 'title'));
});
